<?php

declare(strict_types=1);

namespace Tests\Unit\Scraping;

use App\Contracts\Repositories\ReviewRepository;
use App\Exceptions\Scraping\InvalidSourceUrlException;
use App\Services\Scraping\SourceRegistry;
use App\Services\Scraping\Yandex\YandexMapsSource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The registry is what makes a second platform a one-line change. These tests
 * cover the resolution it performs and, as a side effect, confirm the container
 * wiring actually produces a usable source.
 */
final class SourceRegistryTest extends TestCase
{
    private function registry(): SourceRegistry
    {
        return app(SourceRegistry::class);
    }

    #[Test]
    public function the_configured_registry_knows_yandex(): void
    {
        $this->assertSame(['yandex_maps'], $this->registry()->keys());
    }

    #[Test]
    public function it_resolves_a_yandex_link_to_the_yandex_source(): void
    {
        $source = $this->registry()->forUrl('https://yandex.ru/maps/org/test/1124715036/');

        $this->assertInstanceOf(YandexMapsSource::class, $source);
        $this->assertSame('yandex_maps', $source->key());
    }

    #[Test]
    public function it_rejects_a_link_from_an_unsupported_platform(): void
    {
        $this->expectException(InvalidSourceUrlException::class);

        $this->registry()->forUrl('https://2gis.ru/moscow/firm/70000001006584611');
    }

    #[Test]
    public function the_rejection_lists_what_is_supported(): void
    {
        try {
            $this->registry()->forUrl('https://example.com/nope');
            $this->fail('Expected the registry to reject an unknown platform');
        } catch (InvalidSourceUrlException $e) {
            // The context is what a developer reads when debugging a rejection
            $this->assertSame(['yandex_maps'], $e->context()['supported']);
        }
    }

    #[Test]
    public function it_resolves_by_stored_key_so_a_queued_job_can_rebuild_the_source(): void
    {
        // The job holds only the key persisted on the organization row
        $this->assertSame('yandex_maps', $this->registry()->forKey('yandex_maps')->key());
    }

    #[Test]
    public function an_unknown_key_fails_loudly(): void
    {
        $this->expectException(InvalidSourceUrlException::class);

        $this->registry()->forKey('2gis');
    }

    #[Test]
    public function the_repository_contracts_resolve_to_a_single_shared_wiring(): void
    {
        // Guards the provider bindings: a missing one surfaces here rather than
        // at runtime inside a queued job
        $this->assertInstanceOf(ReviewRepository::class, app(ReviewRepository::class));
    }
}
