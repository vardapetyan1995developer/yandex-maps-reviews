<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex;

use App\Contracts\ReviewsSource;
use App\Contracts\ScrapeStrategy;
use App\Data\ScrapeResult;
use App\Data\SourceReference;
use App\Exceptions\Scraping\ScrapingException;
use App\Exceptions\Scraping\SourceSchemaChangedException;
use App\Exceptions\Scraping\SourceUnavailableException;
use Illuminate\Support\Facades\Log;

/**
 * The Yandex.Maps source.
 *
 * It holds no extraction logic of its own; its job is to arrange the strategies
 * into a chain and decide when to move to the next one.
 *
 * The switching rule is deliberately non-obvious: the fallback does not fire on
 * just any error. If the source is merely unavailable or we have been blocked,
 * retrying with a different strategy from the same address changes nothing —
 * that is the queue's job, with backoff. A fallback is only meaningful in one
 * case: the fast path's contract changed, and a real browser driving the actual
 * interface can still cope.
 */
final class YandexMapsSource implements ReviewsSource
{
    /**
     * @param  list<ScrapeStrategy>  $strategies  in priority order
     */
    public function __construct(
        private readonly YandexUrlParser $urlParser,
        private readonly array $strategies,
    ) {}

    public function key(): string
    {
        return YandexUrlParser::SOURCE_KEY;
    }

    public function name(): string
    {
        return 'Яндекс.Карты';
    }

    public function supports(string $url): bool
    {
        return $this->urlParser->supports($url);
    }

    public function reference(string $url): SourceReference
    {
        return $this->urlParser->parse($url);
    }

    public function scrape(SourceReference $reference, ?callable $onProgress = null): ScrapeResult
    {
        $available = array_values(array_filter(
            $this->strategies,
            static fn (ScrapeStrategy $strategy): bool => $strategy->isAvailable(),
        ));

        if ($available === []) {
            throw new SourceUnavailableException(
                'Нет ни одной доступной стратегии парсинга',
                ['source' => $this->key()],
            );
        }

        $lastException = null;

        foreach ($available as $index => $strategy) {
            try {
                $result = $strategy->scrape($reference, $onProgress);

                if ($index > 0) {
                    Log::info('Parse completed via a fallback strategy', [
                        'source' => $this->key(),
                        'external_id' => $reference->externalId,
                        'strategy' => $strategy->key(),
                    ]);
                }

                return $result;
            } catch (ScrapingException $e) {
                $lastException = $e;

                if (! $this->shouldFallback($e)) {
                    throw $e;
                }

                Log::warning('Scrape strategy failed, trying the next one', [
                    'source' => $this->key(),
                    'external_id' => $reference->externalId,
                    'strategy' => $strategy->key(),
                    'reason' => $e->reason()->value,
                    'message' => $e->getMessage(),
                    'context' => $e->context(),
                ]);
            }
        }

        throw $lastException;
    }

    /**
     * A fallback is only justified when the source's contract has changed.
     *
     * Blocks and network failures are not cured by a retry — the queue handles
     * those with backoff, and spinning up a heavy browser for them is pointless.
     */
    private function shouldFallback(ScrapingException $exception): bool
    {
        return $exception instanceof SourceSchemaChangedException;
    }
}
