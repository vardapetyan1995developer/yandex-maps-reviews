<?php

declare(strict_types=1);

namespace App\Services\Scraping\Yandex;

use App\Data\OrganizationData;

final readonly class BootstrapResult
{
    public function __construct(
        public YandexSession $session,
        public OrganizationData $organization,
    ) {}
}
