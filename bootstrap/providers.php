<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\RepositoryServiceProvider;
use App\Providers\ScrapingServiceProvider;

return [
    AppServiceProvider::class,
    RepositoryServiceProvider::class,
    ScrapingServiceProvider::class,
];
