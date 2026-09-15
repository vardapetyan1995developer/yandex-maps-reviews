<?php

declare(strict_types=1);

namespace Tests\Unit\Scraping;

use App\Exceptions\Scraping\EmptyResponseException;
use App\Exceptions\Scraping\SourceBlockedException;
use App\Exceptions\Scraping\SourceSchemaChangedException;
use App\Exceptions\Scraping\SourceUnavailableException;
use App\Services\Scraping\Yandex\ReviewsResponseValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Checks that the parser notices when the source breaks.
 *
 * The dangerous scenario is not a crash but silent degradation: the request
 * went through, a response came back, and there is no data. These tests pin
 * down that every such case raises an exception with a specific cause rather
 * than returning an empty array.
 */
final class ReviewsResponseValidatorTest extends TestCase
{
    private ReviewsResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ReviewsResponseValidator;
    }

    private function validPayload(): array
    {
        return [
            'data' => [
                'reviews' => [[
                    'reviewId' => 'abc123',
                    'author' => ['name' => 'Иван'],
                    'rating' => 5,
                    'text' => 'Отличное место',
                    'updatedTime' => '2026-09-14T18:42:52.279Z',
                ]],
                'params' => [
                    'page' => 1,
                    'limit' => 50,
                    'count' => 137,
                    'totalPages' => 3,
                    'reviewsRemained' => 87,
                ],
            ],
        ];
    }

    #[Test]
    public function it_accepts_a_well_formed_response(): void
    {
        $result = $this->validator->validate($this->validPayload(), []);

        $this->assertCount(1, $result['reviews']);
        $this->assertSame(137, $result['params']['count']);
    }

    #[Test]
    public function it_accepts_a_genuinely_empty_page(): void
    {
        // Zero reviews is not in itself an error: a business may simply have
        // none. Whether to treat it as breakage is decided further up, by
        // reconciling the collected count against the card's declared one.
        $payload = $this->validPayload();
        $payload['data']['reviews'] = [];

        $this->assertSame([], $this->validator->validate($payload, [])['reviews']);
    }

    #[Test]
    public function it_detects_invalid_json(): void
    {
        $this->expectException(SourceSchemaChangedException::class);

        $this->validator->validate(null, []);
    }

    #[Test]
    public function it_detects_a_missing_data_section(): void
    {
        $this->expectException(SourceSchemaChangedException::class);

        $this->validator->validate(['unexpected' => true], []);
    }

    #[Test]
    public function it_detects_renamed_review_fields(): void
    {
        // Exactly what a contract change looks like: the structure is intact
        // but the keys were renamed, so the mapping would silently yield blanks
        $payload = $this->validPayload();
        $payload['data']['reviews'][0] = [
            'id' => 'abc123',
            'author' => ['name' => 'Иван'],
            'score' => 5,
            'date' => '2026-09-14',
        ];

        $this->expectException(SourceSchemaChangedException::class);
        $this->expectExceptionMessageMatches('~reviewId~');

        $this->validator->validate($payload, []);
    }

    #[Test]
    public function it_detects_a_missing_pagination_block(): void
    {
        $payload = $this->validPayload();
        unset($payload['data']['params']['totalPages']);

        $this->expectException(SourceSchemaChangedException::class);
        $this->expectExceptionMessageMatches('~totalPages~');

        $this->validator->validate($payload, []);
    }

    #[Test]
    public function it_detects_a_captcha_response(): void
    {
        $this->expectException(SourceBlockedException::class);

        $this->validator->validate(['type' => 'captcha', 'captcha' => ['url' => '...']], []);
    }

    #[Test]
    public function it_detects_an_error_embedded_in_a_successful_response(): void
    {
        // The source serves failures with HTTP 200 and a nested error object —
        // a status-code check would not see them
        $this->expectException(SourceUnavailableException::class);

        $this->validator->validate(
            ['error' => ['code' => 500, 'message' => 'Internal error in /business/fetchReviews']],
            [],
        );
    }

    #[Test]
    public function it_treats_rejected_parameters_as_a_contract_change(): void
    {
        $this->expectException(SourceSchemaChangedException::class);

        $this->validator->validate(
            ['error' => ['code' => 400, 'message' => 'Validation error. "query.locale": Required']],
            [],
        );
    }

    #[Test]
    public function it_treats_rate_limiting_as_blocking(): void
    {
        $this->expectException(SourceBlockedException::class);

        $this->validator->validate(['error' => ['code' => 429, 'message' => 'Too many requests']], []);
    }

    #[Test]
    public function it_treats_a_missing_resource_as_an_empty_response(): void
    {
        $this->expectException(EmptyResponseException::class);

        $this->validator->validate(['error' => ['code' => 404, 'message' => 'Not found']], []);
    }

    #[Test]
    public function it_preserves_diagnostic_context_on_failure(): void
    {
        try {
            $this->validator->validate(['unexpected' => true], ['page' => 7, 'external_id' => '42']);
            $this->fail('Expected a schema-change exception');
        } catch (SourceSchemaChangedException $e) {
            // Without context, debugging a broken parser in production is impossible
            $this->assertSame(7, $e->context()['page']);
            $this->assertSame('42', $e->context()['external_id']);
            $this->assertContains('unexpected', $e->context()['payload_keys']);
        }
    }
}
