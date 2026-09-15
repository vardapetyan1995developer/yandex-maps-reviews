<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Scraping\ScrapingException;
use App\Services\Scraping\SourceRegistry;
use Illuminate\Console\Command;

/**
 * Exercises the parser without involving the queue or the interface.
 *
 * Useful in two situations: during development, to see exactly what the source
 * returns, and in production, to tell "the parser broke" apart from "the queue
 * broke" when data stops refreshing.
 */
final class ScrapeOrganizationCommand extends Command
{
    protected $signature = 'scrape:check {url : Link to an organization card}
                            {--reviews=5 : How many reviews to print}';

    protected $description = 'Parse an organization card and print the result without writing to the database';

    public function handle(SourceRegistry $registry): int
    {
        $url = (string) $this->argument('url');

        try {
            $source = $registry->forUrl($url);
            $reference = $source->reference($url);
        } catch (ScrapingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Source: {$source->name()}");
        $this->line("Card identifier: {$reference->externalId}");
        $this->line("URL: {$reference->reviewsUrl()}");
        $this->newLine();

        $startedAt = microtime(true);

        try {
            $result = $source->scrape($reference, function ($progress): void {
                $this->output->write(sprintf(
                    "\rPages: %d, reviews: %d (%d%%)",
                    $progress->pagesFetched,
                    $progress->reviewsFetched,
                    $progress->percent(),
                ));
            });
        } catch (ScrapingException $e) {
            $this->newLine(2);
            $this->error("Parse failed: {$e->getMessage()}");
            $this->line("Reason: {$e->reason()->value} ({$e->reason()->label()})");
            $this->line('Context: '.json_encode($e->context(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $startedAt, 1);

        $this->newLine(2);
        $this->table(['Metric', 'Value'], [
            ['Name', $result->organization->name],
            ['Address', $result->organization->address ?? '—'],
            ['Average rating', $result->organization->rating ?? '—'],
            ['Ratings count', $result->organization->ratingsCount],
            ['Reviews count', $result->organization->reviewsCount],
            ['Reviews collected', $result->reviewCount()],
            ['Completeness', round($result->completeness() * 100).'%'],
            ['Strategy', $result->strategy],
            ['Pages fetched', $result->pagesFetched],
            ['Truncated', $result->truncated ? 'yes ('.$result->truncationReason.')' : 'no'],
            ['Elapsed', "{$elapsed}s"],
        ]);

        $limit = (int) $this->option('reviews');

        foreach (array_slice($result->reviews, 0, $limit) as $review) {
            $this->newLine();
            $this->line(sprintf(
                '<comment>%s</comment> — %s, rating %s',
                $review->authorName,
                $review->publishedAt?->format('d.m.Y') ?? 'no date',
                $review->rating ?? '—',
            ));
            $this->line(mb_substr((string) $review->text, 0, 200));
        }

        return self::SUCCESS;
    }
}
