<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ScrapeProgress;
use App\Data\ScrapeResult;
use App\Data\SourceReference;
use App\Exceptions\Scraping\ScrapingException;

/**
 * A platform we collect reviews from (Yandex.Maps, 2GIS, ...).
 *
 * The contract deliberately knows nothing about HTTP or headless browsers: how
 * the data is obtained is an implementation detail of the concrete source, and
 * it may change — or use several strategies with a fallback — without touching
 * any calling code.
 */
interface ReviewsSource
{
    /** Stable source key, persisted in the database. */
    public function key(): string;

    /** Human-readable name for the interface. */
    public function name(): string;

    /** Whether this source can handle the given link. */
    public function supports(string $url): bool;

    /**
     * Resolve a link into a card identifier.
     *
     * @throws ScrapingException
     */
    public function reference(string $url): SourceReference;

    /**
     * Full run: the organization card plus every available review.
     *
     * @param  null|callable(ScrapeProgress): void  $onProgress
     *
     * @throws ScrapingException
     */
    public function scrape(SourceReference $reference, ?callable $onProgress = null): ScrapeResult;
}
