<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex;

/**
 * The page state required to talk to the internal API.
 *
 * The token and session identifier are short-lived and tied to the cookies
 * issued when the page loaded, so one session is reused across every review
 * page of an organization rather than being re-established per request.
 */
final readonly class YandexSession
{
    /**
     * @param  array<string, string>  $cookies
     */
    public function __construct(
        public string $csrfToken,
        public string $sessionId,
        public string $locale,
        public string $origin,
        public string $apiBaseUrl,
        public array $cookies,
    ) {}

    public function endpoint(string $method): string
    {
        return rtrim($this->origin, '/').$this->apiBaseUrl.'/api/'.$method;
    }
}
