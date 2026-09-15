<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex;

use App\Exceptions\Scraping\EmptyResponseException;
use App\Exceptions\Scraping\SourceBlockedException;
use App\Exceptions\Scraping\SourceSchemaChangedException;
use App\Exceptions\Scraping\SourceUnavailableException;

/**
 * Validates responses from the internal reviews API.
 *
 * This is the answer to "how will the parser know it broke". Catching
 * exceptions is not enough: the dangerous scenario is not a crash but silent
 * degradation, where the request formally succeeds but the data is missing or
 * wrong.
 *
 * Two traits of this source in particular defeat a naive
 * `$response->successful()` check:
 *
 *   1. Errors arrive with HTTP status 200 and a nested `error` object
 *      (e.g. {"error":{"code":500,...}}), so the status code cannot
 *      distinguish success from failure.
 *   2. A captcha also arrives with status 200, but carries `type: "captcha"`.
 *
 * Hence the shape of the response body is validated, not the status code.
 */
final class ReviewsResponseValidator
{
    /**
     * Mandatory review fields. Losing any of them means the format changed and
     * the mapping no longer matches the source.
     */
    private const REQUIRED_REVIEW_FIELDS = ['reviewId', 'rating', 'updatedTime'];

    /**
     * Mandatory pagination fields. They tell the parser how many pages exist
     * and when to stop.
     */
    private const REQUIRED_PARAM_FIELDS = ['page', 'limit', 'count', 'totalPages'];

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $context
     * @return array{reviews: list<array<string, mixed>>, params: array<string, mixed>}
     */
    public function validate(?array $payload, array $context): array
    {
        if ($payload === null) {
            throw new SourceSchemaChangedException(
                'Ответ API отзывов не является валидным JSON',
                $context,
            );
        }

        $this->guardAgainstCaptcha($payload, $context);
        $this->guardAgainstEmbeddedError($payload, $context);

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new SourceSchemaChangedException(
                'В ответе API отзывов отсутствует секция data',
                array_merge($context, ['payload_keys' => array_keys($payload)]),
            );
        }

        $reviews = $data['reviews'] ?? null;
        $params = $data['params'] ?? null;

        if (! is_array($reviews)) {
            throw new SourceSchemaChangedException(
                'В ответе API отзывов отсутствует массив reviews',
                array_merge($context, ['data_keys' => array_keys($data)]),
            );
        }

        if (! is_array($params)) {
            throw new SourceSchemaChangedException(
                'В ответе API отзывов отсутствует блок params с пагинацией',
                array_merge($context, ['data_keys' => array_keys($data)]),
            );
        }

        $this->assertParamsShape($params, $context);
        $this->assertReviewsShape($reviews, $context);

        return ['reviews' => array_values($reviews), 'params' => $params];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private function guardAgainstCaptcha(array $payload, array $context): void
    {
        if (($payload['type'] ?? null) === 'captcha' || isset($payload['captcha'])) {
            throw new SourceBlockedException(
                'Вместо отзывов Яндекс вернул капчу',
                array_merge($context, ['captcha' => $payload['captcha'] ?? null]),
            );
        }
    }

    /**
     * An error nested inside an otherwise successful response.
     *
     * A concrete example: requesting reviews with offset >= 600 consistently
     * returns HTTP 200 with a body of {"error":{"code":500,...}}. That is
     * neither a network failure nor a format change but a hard limit on output
     * depth, and it has to be handled separately from genuine breakage.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private function guardAgainstEmbeddedError(array $payload, array $context): void
    {
        $error = $payload['error'] ?? null;

        if (! is_array($error)) {
            return;
        }

        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['message'] ?? 'неизвестная ошибка');
        $context = array_merge($context, ['source_error' => $error]);

        if ($code === 429 || str_contains(mb_strtolower($message), 'captcha')) {
            throw new SourceBlockedException("Источник ограничил доступ: {$message}", $context);
        }

        if ($code === 404) {
            throw new EmptyResponseException("Источник не нашёл данные: {$message}", $context);
        }

        // A 400 means the server rejected our parameter set or signature —
        // that is a contract change, not a transient outage
        if ($code === 400) {
            throw new SourceSchemaChangedException(
                "Источник отверг параметры запроса: {$message}",
                $context,
            );
        }

        throw new SourceUnavailableException("Источник вернул ошибку: {$message}", $context);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $context
     */
    private function assertParamsShape(array $params, array $context): void
    {
        $missing = array_values(array_diff(self::REQUIRED_PARAM_FIELDS, array_keys($params)));

        if ($missing !== []) {
            throw new SourceSchemaChangedException(
                'В блоке пагинации пропали ожидаемые поля: '.implode(', ', $missing),
                array_merge($context, ['params' => $params]),
            );
        }
    }

    /**
     * @param  array<int, mixed>  $reviews
     * @param  array<string, mixed>  $context
     */
    private function assertReviewsShape(array $reviews, array $context): void
    {
        if ($reviews === []) {
            return;
        }

        $first = $reviews[0] ?? null;

        if (! is_array($first)) {
            throw new SourceSchemaChangedException(
                'Элемент массива отзывов не является объектом',
                array_merge($context, ['first_type' => get_debug_type($first)]),
            );
        }

        $missing = array_values(array_diff(self::REQUIRED_REVIEW_FIELDS, array_keys($first)));

        if ($missing !== []) {
            throw new SourceSchemaChangedException(
                'В отзыве пропали ожидаемые поля: '.implode(', ', $missing),
                array_merge($context, ['review_keys' => array_keys($first)]),
            );
        }

        // The author may be hidden (an anonymous review), but the key itself
        // must be present — otherwise the structure changed, not the data
        if (! array_key_exists('author', $first)) {
            throw new SourceSchemaChangedException(
                'В отзыве отсутствует блок author',
                array_merge($context, ['review_keys' => array_keys($first)]),
            );
        }
    }
}
