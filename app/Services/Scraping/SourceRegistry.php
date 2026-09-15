<?php

declare(strict_types=1);

namespace App\Services\Scraping;

use App\Contracts\ReviewsSource;
use App\Exceptions\Scraping\InvalidSourceUrlException;

/**
 * Registry of available sources.
 *
 * The single point where the application works out which platform a link
 * belongs to. It exists for the sake of the second source: adding 2GIS means
 * implementing ReviewsSource and registering it here — controllers, jobs and
 * models stay untouched.
 */
final class SourceRegistry
{
    /** @var array<string, ReviewsSource> */
    private array $sources = [];

    /**
     * @param  iterable<ReviewsSource>  $sources
     */
    public function __construct(iterable $sources = [])
    {
        foreach ($sources as $source) {
            $this->register($source);
        }
    }

    public function register(ReviewsSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /**
     * @throws InvalidSourceUrlException
     */
    public function forUrl(string $url): ReviewsSource
    {
        foreach ($this->sources as $source) {
            if ($source->supports($url)) {
                return $source;
            }
        }

        throw new InvalidSourceUrlException(
            'Ссылка не относится ни к одной из поддерживаемых площадок',
            ['url' => $url, 'supported' => array_keys($this->sources)],
        );
    }

    /**
     * @throws InvalidSourceUrlException
     */
    public function forKey(string $key): ReviewsSource
    {
        if (! isset($this->sources[$key])) {
            throw new InvalidSourceUrlException(
                "Источник «{$key}» не зарегистрирован",
                ['key' => $key, 'supported' => array_keys($this->sources)],
            );
        }

        return $this->sources[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->sources);
    }
}
