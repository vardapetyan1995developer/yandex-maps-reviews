<?php

declare(strict_types=1);

namespace Tests\Unit\Scraping;

use App\Services\Scraping\Support\ProxyPool;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The README claims rotation withdraws a blocked address rather than continuing
 * to hammer it. That is the whole point of the pool — plain rotation without it
 * makes matters worse — so the behaviour is pinned here rather than asserted in
 * prose alone.
 */
final class ProxyPoolTest extends TestCase
{
    private const A = 'http://a.example:8080';

    private const B = 'http://b.example:8080';

    #[Test]
    public function an_empty_pool_hands_back_nothing(): void
    {
        $pool = new ProxyPool([]);

        $this->assertTrue($pool->isEmpty());
        // null means "go out from the server's own address", not an error
        $this->assertNull($pool->acquire());
    }

    #[Test]
    public function it_only_hands_out_addresses_from_the_pool(): void
    {
        $pool = new ProxyPool([self::A, self::B]);

        $this->assertContains($pool->acquire(), [self::A, self::B]);
        $this->assertSame(2, $pool->healthyCount());
    }

    #[Test]
    public function a_blocked_address_leaves_the_rotation(): void
    {
        $pool = new ProxyPool([self::A, self::B]);
        $pool->markBlocked(self::A);

        $this->assertSame(1, $pool->healthyCount());

        // Repeated draws must never return the blocked one
        for ($i = 0; $i < 12; $i++) {
            $this->assertSame(self::B, $pool->acquire());
        }
    }

    #[Test]
    public function the_pool_falls_back_to_the_server_address_when_all_are_blocked(): void
    {
        $pool = new ProxyPool([self::A, self::B]);
        $pool->markBlocked(self::A);
        $pool->markBlocked(self::B);

        $this->assertSame(0, $pool->healthyCount());
        // Better to continue from the server's own IP than to stop entirely
        $this->assertNull($pool->acquire());
    }

    #[Test]
    public function an_address_returns_to_rotation_once_marked_healthy(): void
    {
        $pool = new ProxyPool([self::A, self::B]);
        $pool->markBlocked(self::A);
        $pool->markHealthy(self::A);

        $this->assertSame(2, $pool->healthyCount());
    }

    #[Test]
    public function marking_null_is_a_no_op(): void
    {
        // acquire() returns null on an empty pool; callers pass that straight
        // back in, so both calls have to tolerate it
        $pool = new ProxyPool([self::A]);
        $pool->markBlocked(null);
        $pool->markHealthy(null);

        $this->assertSame(1, $pool->healthyCount());
    }

    #[Test]
    public function blanks_in_the_configured_list_are_ignored(): void
    {
        // SCRAPING_PROXIES= produces one empty string after exploding
        $pool = new ProxyPool(['', '  ', self::A]);

        $this->assertFalse($pool->isEmpty());
        $this->assertSame(1, $pool->healthyCount());
        $this->assertSame(self::A, $pool->acquire());
    }
}
