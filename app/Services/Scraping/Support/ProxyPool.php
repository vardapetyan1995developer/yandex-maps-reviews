<?php

declare(strict_types=1);

namespace App\Services\Scraping\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A proxy pool that tracks the health of each address.
 *
 * The point is not the rotation itself but that a blocked address is taken out
 * of service for a cooldown. Without that, rotation actively makes things
 * worse: we keep hammering away from a blocked IP and extend the ban.
 *
 * State lives in the cache rather than in process memory because there are
 * several queue workers and they must see each other's blocks.
 */
final class ProxyPool
{
    private const CACHE_PREFIX = 'scraping:proxy:';

    /** @var list<string> */
    private array $proxies;

    public function __construct(
        array $proxies = [],
        private readonly int $cooldownSeconds = 900,
    ) {
        $this->proxies = array_values(array_filter(array_map('trim', $proxies)));
    }

    public function isEmpty(): bool
    {
        return $this->proxies === [];
    }

    /**
     * A random healthy proxy, or null — in which case the request goes out from
     * the server's own address.
     */
    public function acquire(): ?string
    {
        $healthy = array_values(array_filter(
            $this->proxies,
            fn (string $proxy): bool => ! $this->isCoolingDown($proxy),
        ));

        if ($healthy === []) {
            return null;
        }

        return $healthy[array_rand($healthy)];
    }

    /**
     * Flag a proxy as blocked — it drops out of rotation for the cooldown.
     */
    public function markBlocked(?string $proxy): void
    {
        if ($proxy === null) {
            return;
        }

        Cache::put(self::CACHE_PREFIX.md5($proxy), true, $this->cooldownSeconds);
    }

    public function markHealthy(?string $proxy): void
    {
        if ($proxy === null) {
            return;
        }

        Cache::forget(self::CACHE_PREFIX.md5($proxy));
    }

    /** How many addresses are currently in rotation — a monitoring metric. */
    public function healthyCount(): int
    {
        return count(array_filter(
            $this->proxies,
            fn (string $proxy): bool => ! $this->isCoolingDown($proxy),
        ));
    }

    private function isCoolingDown(string $proxy): bool
    {
        return Cache::has(self::CACHE_PREFIX.md5($proxy));
    }
}
