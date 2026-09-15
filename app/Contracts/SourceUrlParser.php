<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\SourceReference;
use App\Exceptions\Scraping\InvalidSourceUrlException;

/**
 * Resolves a user-supplied link into a card identifier.
 *
 * Kept as a separate contract because validating the shape of a link has to
 * happen synchronously (in a FormRequest, before anything is queued) whereas
 * the parse itself is asynchronous. These operations differ by orders of
 * magnitude in cost and should not be conflated.
 */
interface SourceUrlParser
{
    public function supports(string $url): bool;

    /**
     * @throws InvalidSourceUrlException
     */
    public function parse(string $url): SourceReference;
}
