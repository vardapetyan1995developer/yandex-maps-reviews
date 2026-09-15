<?php

declare(strict_types=1);

namespace App\Exceptions\Scraping;

use App\Enums\FailureReason;

final class InvalidSourceUrlException extends ScrapingException
{
    public function reason(): FailureReason
    {
        return FailureReason::InvalidUrl;
    }
}
