<?php

declare(strict_types=1);

namespace App\Services\Scraping\Support;

/**
 * User-Agent rotation.
 *
 * The important detail: the User-Agent is never rotated on its own, but
 * together with a consistent set of headers (Sec-CH-UA, platform). Inconsistent
 * headers — a Chrome User-Agent paired with Firefox client hints — give a bot
 * away far more reliably than a User-Agent that never changes at all.
 */
final class UserAgentRotator
{
    /**
     * @var list<array{ua: string, platform: string, brands: string, mobile: string}>
     */
    private const PROFILES = [
        [
            'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            'platform' => '"macOS"',
            'brands' => '"Chromium";v="140", "Not=A?Brand";v="24", "Google Chrome";v="140"',
            'mobile' => '?0',
        ],
        [
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
            'platform' => '"Windows"',
            'brands' => '"Chromium";v="139", "Not=A?Brand";v="24", "Google Chrome";v="139"',
            'mobile' => '?0',
        ],
        [
            'ua' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            'platform' => '"Linux"',
            'brands' => '"Chromium";v="140", "Not=A?Brand";v="24", "Google Chrome";v="140"',
            'mobile' => '?0',
        ],
        [
            'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
            'platform' => '"macOS"',
            'brands' => '',
            'mobile' => '?0',
        ],
    ];

    /** @var array{ua: string, platform: string, brands: string, mobile: string} */
    private array $profile;

    public function __construct()
    {
        $this->profile = self::PROFILES[array_rand(self::PROFILES)];
    }

    /**
     * The profile stays fixed for the whole of one organization's run: swapping
     * User-Agents between pages of a single session looks more suspicious than
     * a stable profile.
     */
    public function pin(): void
    {
        // the profile was already chosen in the constructor; this method exists
        // to make that intent explicit at the call site
    }

    public function userAgent(): string
    {
        return $this->profile['ua'];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $headers = [
            'User-Agent' => $this->profile['ua'],
            'Accept-Language' => 'ru,en;q=0.9',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
        ];

        // Only Chromium sends client hints — Safari must not have them
        if ($this->profile['brands'] !== '') {
            $headers['Sec-CH-UA'] = $this->profile['brands'];
            $headers['Sec-CH-UA-Mobile'] = $this->profile['mobile'];
            $headers['Sec-CH-UA-Platform'] = $this->profile['platform'];
        }

        return $headers;
    }
}
