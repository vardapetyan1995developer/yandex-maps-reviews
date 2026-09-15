<?php

declare(strict_types=1);

namespace Tests\Unit\Scraping;

use App\Services\Scraping\Yandex\RequestSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Request signing is the most brittle part of the integration: it reproduces an
 * algorithm from Yandex's client bundle. Reference vectors are pinned here so
 * that an accidental change to the implementation cannot break the parser
 * silently.
 */
final class RequestSignerTest extends TestCase
{
    private RequestSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new RequestSigner;
    }

    /**
     * Values produced by running the reference DJB2-with-XOR implementation
     * (seed 5381, `hash = (hash * 33) ^ code`, result as uint32).
     */
    public static function hashVectors(): array
    {
        return [
            'empty string returns the seed' => ['', '5381'],
            'single character' => ['a', '177604'],
            'three characters' => ['abc', '193409669'],
            'a real query string' => [
                'ajax=1&businessId=1124715036&csrfToken=TESTTOKEN%3A123&locale=ru_AM'
                .'&page=1&pageSize=50&ranking=by_time&sessionId=sess-abc',
                '241859068',
            ],
        ];
    }

    #[Test]
    #[DataProvider('hashVectors')]
    public function it_reproduces_reference_hash_values(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->signer->hash($input));
    }

    #[Test]
    public function it_sorts_keys_case_insensitively(): void
    {
        // Input key order must not affect the result: case-insensitive sorting
        // is part of the algorithm
        $a = $this->signer->buildQuery(['pageSize' => 50, 'ajax' => '1', 'Locale' => 'ru']);
        $b = $this->signer->buildQuery(['Locale' => 'ru', 'ajax' => '1', 'pageSize' => 50]);

        $this->assertSame($a, $b);
        $this->assertSame('ajax=1&Locale=ru&pageSize=50', $a);
    }

    #[Test]
    public function it_encodes_values_per_rfc3986(): void
    {
        $query = $this->signer->buildQuery(['csrfToken' => 'abc:123', 'q' => 'a b']);

        // A colon must be encoded, and a space as %20 rather than +
        $this->assertSame('csrfToken=abc%3A123&q=a%20b', $query);
    }

    #[Test]
    public function it_serialises_null_as_empty_value(): void
    {
        $this->assertSame('host_exp=', $this->signer->buildQuery(['host_exp' => null]));
    }

    #[Test]
    public function it_appends_signature_parameter(): void
    {
        $signed = $this->signer->sign(['ajax' => '1']);

        $this->assertStringStartsWith('ajax=1&s=', $signed);
    }

    #[Test]
    public function it_handles_non_ascii_values_as_utf16_code_units(): void
    {
        // charCodeAt in JS operates on UTF-16 code units; check that Cyrillic
        // does not break the computation
        $this->assertMatchesRegularExpression('~^\d+$~', $this->signer->hash('кафе'));
    }
}
