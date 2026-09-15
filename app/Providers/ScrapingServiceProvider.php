<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Scraping\SourceRegistry;
use App\Services\Scraping\Support\ProxyPool;
use App\Services\Scraping\Support\RequestThrottle;
use App\Services\Scraping\Support\UserAgentRotator;
use App\Services\Scraping\Yandex\Strategies\HeadlessBrowserStrategy;
use App\Services\Scraping\Yandex\Strategies\InternalApiStrategy;
use App\Services\Scraping\Yandex\YandexMapsSource;
use App\Services\Scraping\Yandex\YandexUrlParser;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the scraping layer.
 *
 * Strategies are registered here in priority order: the fast one first, the
 * resilient one second. That ordering is an architectural decision rather than
 * an accident, so it is declared in one place instead of being scattered
 * through the code.
 */
final class ScrapingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(UserAgentRotator::class, static fn (): UserAgentRotator => new UserAgentRotator);

        $this->app->singleton(RequestThrottle::class, static fn ($app): RequestThrottle => new RequestThrottle(
            minDelayMs: (int) $app['config']->get('scraping.delay.min_ms', 400),
            maxDelayMs: (int) $app['config']->get('scraping.delay.max_ms', 1200),
        ));

        $this->app->singleton(ProxyPool::class, static fn ($app): ProxyPool => new ProxyPool(
            proxies: (array) $app['config']->get('scraping.proxies', []),
            cooldownSeconds: (int) $app['config']->get('scraping.proxy_cooldown', 900),
        ));

        $this->app->singleton(HeadlessBrowserStrategy::class, static fn ($app): HeadlessBrowserStrategy => new HeadlessBrowserStrategy(
            nodeBinary: (string) $app['config']->get('scraping.headless.node_binary', 'node'),
            scriptPath: (string) $app['config']->get('scraping.headless.script'),
            enabled: (bool) $app['config']->get('scraping.headless.enabled', false),
            timeoutSeconds: (int) $app['config']->get('scraping.headless.timeout', 300),
        ));

        $this->app->singleton(YandexMapsSource::class, static fn ($app): YandexMapsSource => new YandexMapsSource(
            urlParser: $app->make(YandexUrlParser::class),
            strategies: [
                // Fast path: parsing the card's internal JSON API
                $app->make(InternalApiStrategy::class),
                // Fallback: a real browser, survives a contract change
                $app->make(HeadlessBrowserStrategy::class),
            ],
        ));

        $this->app->singleton(SourceRegistry::class, static fn ($app): SourceRegistry => new SourceRegistry([
            $app->make(YandexMapsSource::class),
            // A new platform (2GIS and so on) is added with a single line here
            // once it implements the ReviewsSource contract
        ]));
    }
}
