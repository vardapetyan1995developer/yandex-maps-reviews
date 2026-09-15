<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\Scraping\ScrapingException;
use App\Services\Scraping\SourceRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a link points at a supported platform and carries an
 * organization identifier.
 *
 * The check is synchronous and cheap: no network access, only the string itself
 * is parsed. Whether the card is actually live is discovered during the parse —
 * a validator must not block an HTTP request on a call to a third-party site.
 */
final class SupportedSourceUrl implements ValidationRule
{
    public function __construct(private readonly SourceRegistry $registry) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Ссылка должна быть строкой.');

            return;
        }

        try {
            $source = $this->registry->forUrl($value);
            $source->reference($value);
        } catch (ScrapingException $e) {
            // The exception message is written for the user and explains what
            // exactly is wrong with the link
            $fail($e->getMessage().'.');
        }
    }
}
