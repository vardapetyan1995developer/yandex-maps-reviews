<?php

declare(strict_types=1);

namespace Tests\Feature\Scraping;

use App\Data\SourceReference;
use App\Enums\FailureReason;
use App\Exceptions\Scraping\SourceBlockedException;
use App\Services\Scraping\Support\ProxyPool;
use App\Services\Scraping\Support\RequestThrottle;
use App\Services\Scraping\Yandex\Strategies\InternalApiStrategy;
use App\Services\Scraping\Yandex\YandexUrlParser;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The fast path end to end, against synthetic responses shaped like the real
 * ones: a card page carrying the state-view JSON, then fetchReviews pages.
 *
 * Three behaviours are pinned here because each was found by running against
 * live cards rather than by reasoning: pagination stops on a short page, the
 * depth limit is honoured without a doomed thirteenth request, and a block
 * partway through keeps what was collected while still taking the proxy out
 * of rotation.
 */
final class InternalApiStrategyTest extends TestCase
{
    private const PROXY = 'http://proxy.example:8080';

    private const BUSINESS_ID = '1124715036';

    private const PAGE_SIZE = 50;

    private ProxyPool $proxies;

    protected function setUp(): void
    {
        parent::setUp();

        $this->proxies = new ProxyPool([self::PROXY], cooldownSeconds: 900);
        $this->app->instance(ProxyPool::class, $this->proxies);

        // No pauses between pages: the tests are about accounting, not pacing
        $this->app->instance(RequestThrottle::class, new RequestThrottle(0, 0));
    }

    #[Test]
    public function it_reads_the_card_and_stops_on_a_short_page(): void
    {
        $this->fakeSource(reviewsCount: 60, pages: [
            1 => fn () => $this->reviewsPage(1, self::PAGE_SIZE, total: 60),
            2 => fn () => $this->reviewsPage(2, 10, total: 60),
        ]);

        $result = $this->strategy()->scrape($this->reference());

        $this->assertSame('Яндекс', $result->organization->name);
        $this->assertEqualsWithDelta(4.9, $result->organization->rating, 0.001);
        $this->assertSame(21229, $result->organization->ratingsCount);
        $this->assertSame(60, $result->organization->reviewsCount);

        $this->assertSame(60, $result->reviewCount());
        $this->assertSame(2, $result->pagesFetched);
        $this->assertFalse($result->truncated);
        $this->assertSame('review-1', $result->reviews[0]->externalId);
        $this->assertSame('review-60', $result->reviews[59]->externalId);

        // A clean run leaves the address in rotation
        $this->assertSame(1, $this->proxies->healthyCount());

        // One card page plus two review pages
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_stops_at_the_depth_limit_without_a_doomed_request(): void
    {
        $pages = [];

        for ($page = 1; $page <= 12; $page++) {
            $pages[$page] = fn () => $this->reviewsPage($page, self::PAGE_SIZE, total: 5864);
        }

        $this->fakeSource(reviewsCount: 5864, pages: $pages);

        $result = $this->strategy()->scrape($this->reference());

        $this->assertSame(600, $result->reviewCount());
        $this->assertSame(12, $result->pagesFetched);
        $this->assertTrue($result->truncated);
        $this->assertSame('source_depth_limit', $result->truncationReason);

        // One card page plus twelve review pages: the thirteenth, which the
        // source rejects, is never sent
        Http::assertSentCount(13);
    }

    #[Test]
    public function a_block_midway_keeps_what_was_collected_and_retires_the_proxy(): void
    {
        $this->fakeSource(reviewsCount: 120, pages: [
            1 => fn () => $this->reviewsPage(1, self::PAGE_SIZE, total: 120),
            2 => fn () => Http::response('', 429),
        ]);

        $result = $this->strategy()->scrape($this->reference());

        $this->assertSame(50, $result->reviewCount());
        $this->assertSame(1, $result->pagesFetched);
        $this->assertTrue($result->truncated);
        $this->assertSame(FailureReason::Blocked->value, $result->truncationReason);

        // The run came back with a partial result rather than an exception,
        // but the address that served it is banned all the same
        $this->assertSame(0, $this->proxies->healthyCount());
    }

    #[Test]
    public function a_block_on_the_first_page_is_a_hard_failure_that_also_retires_the_proxy(): void
    {
        $this->fakeSource(reviewsCount: 120, pages: [
            1 => fn () => Http::response('', 429),
        ]);

        try {
            $this->strategy()->scrape($this->reference());
            $this->fail('A block before any review was read must surface as an exception.');
        } catch (SourceBlockedException) {
            // expected: nothing was collected, so there is nothing to keep
        }

        $this->assertSame(0, $this->proxies->healthyCount());
    }

    private function strategy(): InternalApiStrategy
    {
        return $this->app->make(InternalApiStrategy::class);
    }

    private function reference(): SourceReference
    {
        return (new YandexUrlParser)->parse('https://yandex.ru/maps/org/yandex/'.self::BUSINESS_ID.'/');
    }

    /**
     * Route the card page to the bootstrap HTML and each fetchReviews page to
     * its fixture. Fixtures are closures so a page can be a review batch or an
     * error response alike.
     *
     * @param  array<int, callable(): PromiseInterface>  $pages
     */
    private function fakeSource(int $reviewsCount, array $pages): void
    {
        Http::fake(function (Request $request) use ($reviewsCount, $pages): PromiseInterface {
            if (! str_contains($request->url(), '/api/business/fetchReviews')) {
                return Http::response($this->cardHtml($reviewsCount), 200, ['Content-Type' => 'text/html']);
            }

            $page = $this->pageOf($request);

            if (! isset($pages[$page])) {
                throw new RuntimeException("Unexpected request for reviews page {$page}.");
            }

            return $pages[$page]();
        });
    }

    private function pageOf(Request $request): int
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (int) ($query['page'] ?? 0);
    }

    /**
     * The card page as the source serves it: the whole application state in a
     * `state-view` script, with the card under stack[0].results.items.
     */
    private function cardHtml(int $reviewsCount): string
    {
        $state = [
            'config' => [
                'csrfToken' => 'test-token:1',
                'counters' => ['analytics' => ['sessionId' => 'test-session']],
                'locale' => 'ru_RU',
                'origin' => 'https://yandex.ru',
                'apiBaseUrl' => '/maps',
            ],
            'stack' => [[
                'results' => ['items' => [[
                    'id' => self::BUSINESS_ID,
                    'title' => 'Яндекс',
                    'fullAddress' => 'Москва, улица Льва Толстого, 16',
                    'ratingData' => [
                        // The float tail is how the source really serves it;
                        // the bootstrapper rounds to one decimal
                        'ratingValue' => 4.900000095367432,
                        'ratingCount' => 21229,
                        'reviewCount' => $reviewsCount,
                    ],
                    'categories' => [['name' => 'IT-компания']],
                ]]],
            ]],
        ];

        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return '<!DOCTYPE html><html><head><script class="state-view" type="application/json">'
            .$json
            .'</script></head><body></body></html>';
    }

    /**
     * One fetchReviews page in the source's shape. Review ids run on across
     * pages so a test can tell exactly which page a review came from.
     */
    private function reviewsPage(int $page, int $count, int $total): PromiseInterface
    {
        $reviews = [];

        for ($i = 1; $i <= $count; $i++) {
            $number = ($page - 1) * self::PAGE_SIZE + $i;

            $reviews[] = [
                'reviewId' => "review-{$number}",
                'rating' => 5,
                'updatedTime' => '2026-09-01T10:00:00.000Z',
                'text' => "Отзыв номер {$number}",
                'author' => ['name' => "Автор {$number}", 'avatarUrl' => null],
            ];
        }

        return Http::response([
            'data' => [
                'reviews' => $reviews,
                'params' => [
                    'page' => $page,
                    'limit' => self::PAGE_SIZE,
                    'count' => $total,
                    'totalPages' => (int) ceil($total / self::PAGE_SIZE),
                    'reviewsRemained' => max(0, $total - $page * self::PAGE_SIZE),
                ],
            ],
        ]);
    }
}
