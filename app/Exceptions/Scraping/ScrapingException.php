<?php

declare(strict_types=1);

namespace App\Exceptions\Scraping;

use App\Enums\FailureReason;
use RuntimeException;

/**
 * Base exception for the scraping layer.
 *
 * Every failure must carry a machine-readable cause and a diagnostic context:
 * the queue uses the cause to decide whether to retry, and a developer uses the
 * context to work out what exactly changed on the source's side.
 */
abstract class ScrapingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        protected readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    abstract public function reason(): FailureReason;

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** Text that is safe and useful to show to a user in the interface. */
    public function userMessage(): string
    {
        return $this->reason()->label();
    }
}
