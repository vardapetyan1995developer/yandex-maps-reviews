<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex\Strategies;

use App\Contracts\ScrapeStrategy;
use App\Data\OrganizationData;
use App\Data\ReviewData;
use App\Data\ScrapeProgress;
use App\Data\ScrapeResult;
use App\Data\SourceReference;
use App\Exceptions\Scraping\SourceBlockedException;
use App\Exceptions\Scraping\SourceSchemaChangedException;
use App\Exceptions\Scraping\SourceUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The fallback strategy: a real browser driven by Playwright.
 *
 * It works on a fundamentally different principle from the primary one: instead
 * of reproducing the internal API contract, it opens the reviews page and
 * intercepts the responses that Yandex's own frontend requests. That lets it
 * survive changes to the signing algorithm and the parameter set — precisely
 * the changes that break the fast path.
 *
 * The price: seconds instead of milliseconds, hundreds of megabytes per browser
 * process, and a requirement to keep Node with Playwright installed. Hence the
 * strategy is enabled only through an explicit environment variable and is used
 * as a fallback, never as the default mode.
 *
 * If Node or Playwright are missing, isAvailable() returns false and the
 * strategy simply drops out of the chain — the application stays functional.
 */
final class HeadlessBrowserStrategy implements ScrapeStrategy
{
    public const KEY = 'headless_browser';

    public function __construct(
        private readonly string $nodeBinary,
        private readonly string $scriptPath,
        private readonly bool $enabled,
        private readonly int $timeoutSeconds = 300,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function isAvailable(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! is_file($this->scriptPath)) {
            return false;
        }

        // Check both Node itself and the presence of Playwright: a script
        // without the library would only fail mid-run, and we need to know
        // beforehand
        $probe = new Process([$this->nodeBinary, '-e', 'require.resolve("playwright")'], base_path());
        $probe->setTimeout(15);

        try {
            $probe->run();
        } catch (Throwable) {
            return false;
        }

        return $probe->isSuccessful();
    }

    public function scrape(SourceReference $reference, ?callable $onProgress = null): ScrapeResult
    {
        $process = new Process(
            [
                $this->nodeBinary,
                $this->scriptPath,
                '--url='.$reference->reviewsUrl(),
                '--business-id='.$reference->externalId,
                '--max-reviews='.(int) config('scraping.yandex.max_reviews', 600),
            ],
            base_path(),
            ['NODE_NO_WARNINGS' => '1'],
        );

        $process->setTimeout($this->timeoutSeconds);

        $stderr = '';

        try {
            // The script writes progress to stderr line by line and the result
            // to stdout. Separating the streams lets us surface progress without
            // waiting for completion.
            $process->run(function (string $type, string $buffer) use (&$stderr, $onProgress): void {
                if ($type !== Process::ERR) {
                    return;
                }

                $stderr .= $buffer;
                $this->reportProgress($buffer, $onProgress);
            });
        } catch (ProcessTimedOutException $e) {
            throw new SourceUnavailableException(
                'Headless-браузер не уложился в отведённое время',
                ['timeout' => $this->timeoutSeconds, 'external_id' => $reference->externalId],
                $e,
            );
        }

        if (! $process->isSuccessful()) {
            throw $this->classifyFailure($process->getExitCode(), $stderr, $reference);
        }

        return $this->mapOutput($process->getOutput(), $reference);
    }

    private function reportProgress(string $buffer, ?callable $onProgress): void
    {
        if ($onProgress === null) {
            return;
        }

        foreach (preg_split('~\r?\n~', trim($buffer)) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);

            if (! is_array($decoded) || ($decoded['event'] ?? null) !== 'progress') {
                continue;
            }

            $onProgress(new ScrapeProgress(
                pagesFetched: (int) ($decoded['pages'] ?? 0),
                reviewsFetched: (int) ($decoded['reviews'] ?? 0),
                totalExpected: isset($decoded['total']) ? (int) $decoded['total'] : null,
            ));
        }
    }

    private function classifyFailure(?int $exitCode, string $stderr, SourceReference $reference): Throwable
    {
        $context = [
            'exit_code' => $exitCode,
            'external_id' => $reference->externalId,
            'stderr_excerpt' => mb_substr(trim($stderr), -500),
        ];

        Log::warning('Headless strategy exited with an error', $context);

        // Exit codes are defined by the script itself — see scripts/scrape-yandex.mjs
        return match ($exitCode) {
            2 => new SourceBlockedException('Headless-браузер столкнулся с капчей', $context),
            3 => new SourceSchemaChangedException('Headless-браузер не нашёл ожидаемые элементы на странице', $context),
            default => new SourceUnavailableException('Headless-браузер не смог собрать данные', $context),
        };
    }

    private function mapOutput(string $output, SourceReference $reference): ScrapeResult
    {
        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded) || ! isset($decoded['organization'], $decoded['reviews'])) {
            throw new SourceSchemaChangedException(
                'Headless-браузер вернул результат в неожиданном формате',
                ['external_id' => $reference->externalId, 'output_excerpt' => mb_substr($output, 0, 500)],
            );
        }

        $org = $decoded['organization'];

        $reviews = [];

        foreach ($decoded['reviews'] as $raw) {
            if (! is_array($raw) || ! is_string($raw['externalId'] ?? null)) {
                continue;
            }

            $reviews[] = new ReviewData(
                externalId: $raw['externalId'],
                authorName: (string) ($raw['author'] ?? 'Аноним'),
                authorAvatar: $raw['avatar'] ?? null,
                rating: isset($raw['rating']) ? (int) $raw['rating'] : null,
                text: $raw['text'] ?? null,
                publishedAt: $this->parseDate($raw['publishedAt'] ?? null),
            );
        }

        return new ScrapeResult(
            organization: new OrganizationData(
                externalId: (string) ($org['externalId'] ?? $reference->externalId),
                name: (string) ($org['name'] ?? ''),
                address: $org['address'] ?? null,
                rating: isset($org['rating']) ? round((float) $org['rating'], 1) : null,
                ratingsCount: (int) ($org['ratingsCount'] ?? 0),
                reviewsCount: (int) ($org['reviewsCount'] ?? 0),
                categories: is_array($org['categories'] ?? null) ? $org['categories'] : [],
                raw: [],
            ),
            reviews: $reviews,
            strategy: self::KEY,
            pagesFetched: (int) ($decoded['pagesFetched'] ?? 0),
            truncated: (bool) ($decoded['truncated'] ?? false),
            truncationReason: $decoded['truncationReason'] ?? null,
        );
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
