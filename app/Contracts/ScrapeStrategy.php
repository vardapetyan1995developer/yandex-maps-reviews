<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ScrapeProgress;
use App\Data\ScrapeResult;
use App\Data\SourceReference;
use App\Exceptions\Scraping\ScrapingException;

/**
 * A concrete way of extracting data from a single source.
 *
 * There is more than one precisely because platforms without an API offer no
 * stable contract: the fast path (parsing internal JSON requests) can break
 * after any Yandex release, and then a slower but more resilient fallback is
 * needed.
 */
interface ScrapeStrategy
{
    public function key(): string;

    /**
     * Whether the strategy is ready to run right now.
     *
     * The headless browser, for instance, needs Node and Playwright installed —
     * if they are missing the strategy is simply dropped from the chain rather
     * than blowing up at runtime.
     */
    public function isAvailable(): bool;

    /**
     * @param  null|callable(ScrapeProgress): void  $onProgress
     *
     * @throws ScrapingException
     */
    public function scrape(SourceReference $reference, ?callable $onProgress = null): ScrapeResult;
}
