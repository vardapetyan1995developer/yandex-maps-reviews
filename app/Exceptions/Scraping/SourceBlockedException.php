<?php

declare(strict_types=1);

namespace App\Exceptions\Scraping;

use App\Enums\FailureReason;

final class SourceBlockedException extends ScrapingException
{
    public function reason(): FailureReason
    {
        return FailureReason::Blocked;
    }
}
