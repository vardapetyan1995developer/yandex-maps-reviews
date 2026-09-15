<?php

declare(strict_types=1);

namespace Tests\Unit\Scraping;

use App\Contracts\ScrapeStrategy;
use App\Data\OrganizationData;
use App\Data\ScrapeResult;
use App\Data\SourceReference;
use App\Exceptions\Scraping\SourceBlockedException;
use App\Exceptions\Scraping\SourceSchemaChangedException;
use App\Exceptions\Scraping\SourceUnavailableException;
use App\Services\Scraping\Yandex\YandexMapsSource;
use App\Services\Scraping\Yandex\YandexUrlParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * The fallback policy.
 *
 * This is the one piece of the strategy chain that is a judgement call rather
 * than a mechanism: the browser is engaged only when the fast path's contract
 * has changed, never because the source is merely unavailable or has blocked
 * us. Those are the queue's problem, and spinning up a browser for them burns
 * minutes to achieve what a backoff achieves for free.
 *
 * Without a test this rule lives only in a comment, and the next person to
 * touch the chain has nothing stopping them from widening it.
 *
 * Extends the application test case rather than PHPUnit's: the source logs a
 * warning when it switches strategies, and the Log facade needs a container.
 */
final class YandexMapsSourceTest extends TestCase
{
    private function reference(): SourceReference
    {
        return new SourceReference(
            source: 'yandex_maps',
            externalId: '1',
            slug: 'test',
            canonicalUrl: 'https://yandex.ru/maps/org/test/1',
            originalUrl: 'https://yandex.ru/maps/org/test/1',
        );
    }

    private function scrapeResult(string $strategy): ScrapeResult
    {
        return new ScrapeResult(
            organization: new OrganizationData('1', 'Тест', null, 4.5, 10, 5),
            reviews: [],
            strategy: $strategy,
            pagesFetched: 1,
            truncated: false,
        );
    }

    /**
     * @param  null|Throwable  $throws  what the strategy raises instead of returning
     */
    private function strategy(string $key, bool $available = true, ?Throwable $throws = null): ScrapeStrategy
    {
        return new class($key, $available, $throws, $this->scrapeResult($key)) implements ScrapeStrategy
        {
            public int $calls = 0;

            public function __construct(
                private readonly string $key,
                private readonly bool $available,
                private readonly ?Throwable $throws,
                private readonly ScrapeResult $result,
            ) {}

            public function key(): string
            {
                return $this->key;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function scrape(SourceReference $reference, ?callable $onProgress = null): ScrapeResult
            {
                $this->calls++;

                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $this->result;
            }
        };
    }

    private function source(array $strategies): YandexMapsSource
    {
        return new YandexMapsSource(new YandexUrlParser, $strategies);
    }

    #[Test]
    public function it_uses_the_first_strategy_when_it_succeeds(): void
    {
        $fast = $this->strategy('fast');
        $browser = $this->strategy('browser');

        $result = $this->source([$fast, $browser])->scrape($this->reference());

        $this->assertSame('fast', $result->strategy);
        $this->assertSame(1, $fast->calls);
        // The expensive path must not run when the cheap one worked
        $this->assertSame(0, $browser->calls);
    }

    #[Test]
    public function it_falls_back_when_the_contract_changed(): void
    {
        $fast = $this->strategy('fast', throws: new SourceSchemaChangedException('fields renamed'));
        $browser = $this->strategy('browser');

        $result = $this->source([$fast, $browser])->scrape($this->reference());

        $this->assertSame('browser', $result->strategy);
        $this->assertSame(1, $browser->calls);
    }

    #[Test]
    public function it_does_not_fall_back_when_the_source_blocked_us(): void
    {
        $fast = $this->strategy('fast', throws: new SourceBlockedException('captcha'));
        $browser = $this->strategy('browser');

        // A different strategy from the same address changes nothing; the queue
        // handles this with backoff
        $this->expectException(SourceBlockedException::class);

        try {
            $this->source([$fast, $browser])->scrape($this->reference());
        } finally {
            $this->assertSame(0, $browser->calls);
        }
    }

    #[Test]
    public function it_does_not_fall_back_on_a_network_failure(): void
    {
        $fast = $this->strategy('fast', throws: new SourceUnavailableException('connection reset'));
        $browser = $this->strategy('browser');

        $this->expectException(SourceUnavailableException::class);

        try {
            $this->source([$fast, $browser])->scrape($this->reference());
        } finally {
            $this->assertSame(0, $browser->calls);
        }
    }

    #[Test]
    public function unavailable_strategies_are_skipped_rather_than_failing_the_run(): void
    {
        // Playwright missing is the normal case, not an error
        $browser = $this->strategy('browser', available: false);
        $fast = $this->strategy('fast');

        $result = $this->source([$browser, $fast])->scrape($this->reference());

        $this->assertSame('fast', $result->strategy);
        $this->assertSame(0, $browser->calls);
    }

    #[Test]
    public function it_reports_a_clear_error_when_no_strategy_is_available(): void
    {
        $this->expectException(SourceUnavailableException::class);

        $this->source([$this->strategy('browser', available: false)])->scrape($this->reference());
    }

    #[Test]
    public function the_last_failure_surfaces_when_every_strategy_is_exhausted(): void
    {
        $fast = $this->strategy('fast', throws: new SourceSchemaChangedException('fast changed'));
        $browser = $this->strategy('browser', throws: new SourceSchemaChangedException('browser changed'));

        try {
            $this->source([$fast, $browser])->scrape($this->reference());
            $this->fail('Expected the final failure to propagate');
        } catch (SourceSchemaChangedException $e) {
            // The caller needs the last cause, not the first
            $this->assertSame('browser changed', $e->getMessage());
        }
    }
}
