<?php

declare(strict_types=1);

namespace Tests\Unit\Scraping;

use App\Exceptions\Scraping\InvalidSourceUrlException;
use App\Services\Scraping\Yandex\YandexUrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YandexUrlParserTest extends TestCase
{
    private YandexUrlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new YandexUrlParser;
    }

    public static function validUrls(): array
    {
        return [
            'canonical link' => [
                'https://yandex.ru/maps/org/yandex/1124715036/',
                '1124715036',
                'yandex',
            ],
            'reviews tab' => [
                'https://yandex.ru/maps/org/yandex/1124715036/reviews/',
                '1124715036',
                'yandex',
            ],
            'without a slug' => [
                'https://yandex.ru/maps/org/1124715036/',
                '1124715036',
                null,
            ],
            '.com domain' => [
                'https://yandex.com/maps/org/yandex/1124715036/',
                '1124715036',
                'yandex',
            ],
            'mobile subdomain' => [
                'https://m.yandex.ru/maps/org/yandex/1124715036/',
                '1124715036',
                'yandex',
            ],
            'with map parameters' => [
                'https://yandex.ru/maps/org/yandex/1124715036/?ll=37.5%2C55.7&z=17',
                '1124715036',
                'yandex',
            ],
            'identifier in the query string' => [
                'https://yandex.ru/maps/213/moscow/?oid=1124715036&ll=37.5',
                '1124715036',
                null,
            ],
            'raw Cyrillic slug' => [
                'https://yandex.ru/maps/org/кафе/9622179162/',
                '9622179162',
                'кафе',
            ],
            'percent-encoded Cyrillic slug' => [
                'https://yandex.ru/maps/org/%D0%BA%D0%B0%D1%84%D0%B5/9622179162/',
                '9622179162',
                'кафе',
            ],
        ];
    }

    #[Test]
    #[DataProvider('validUrls')]
    public function it_extracts_identity_from_supported_urls(string $url, string $id, ?string $slug): void
    {
        $reference = $this->parser->parse($url);

        $this->assertSame($id, $reference->externalId);
        $this->assertSame($slug, $reference->slug);
        $this->assertSame(YandexUrlParser::SOURCE_KEY, $reference->source);
        $this->assertStringEndsWith('/reviews/', $reference->reviewsUrl());
    }

    public static function invalidUrls(): array
    {
        return [
            'empty string' => [''],
            'no scheme' => ['yandex.ru/maps/org/yandex/1124715036/'],
            'different platform' => ['https://2gis.ru/moscow/firm/70000001006584611'],
            'look-alike spoofed domain' => ['https://yandex.ru.evil.com/maps/org/x/123/'],
            'map without an organization' => ['https://yandex.ru/maps/213/moscow/'],
            'non-numeric identifier' => ['https://yandex.ru/maps/org/yandex/abcdef/'],
        ];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function it_rejects_unsupported_urls(string $url): void
    {
        $this->assertFalse($this->parser->supports($url));

        $this->expectException(InvalidSourceUrlException::class);
        $this->parser->parse($url);
    }

    #[Test]
    public function it_builds_a_canonical_url_independent_of_query_noise(): void
    {
        $withNoise = $this->parser->parse('https://yandex.ru/maps/org/yandex/1124715036/?ll=1,2&z=17');
        $clean = $this->parser->parse('https://yandex.ru/maps/org/yandex/1124715036/');

        $this->assertSame($clean->canonicalUrl, $withNoise->canonicalUrl);
    }

    #[Test]
    public function it_preserves_the_original_url_for_diagnostics(): void
    {
        $url = 'https://yandex.ru/maps/org/yandex/1124715036/?ll=1,2';

        $this->assertSame($url, $this->parser->parse($url)->originalUrl);
    }
}
