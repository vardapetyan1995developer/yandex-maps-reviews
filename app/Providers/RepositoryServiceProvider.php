<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Repositories\OrganizationRepository;
use App\Contracts\Repositories\ParseRunRepository;
use App\Contracts\Repositories\ReviewRepository;
use App\Repositories\Eloquent\EloquentOrganizationRepository;
use App\Repositories\Eloquent\EloquentParseRunRepository;
use App\Repositories\Eloquent\EloquentReviewRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the repository contracts to their Eloquent implementations.
 *
 * Collected in one provider so the persistence technology is named in exactly
 * one place. Controllers, jobs and services depend on the interfaces and never
 * reference an Eloquent class directly.
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        OrganizationRepository::class => EloquentOrganizationRepository::class,
        ReviewRepository::class => EloquentReviewRepository::class,
        ParseRunRepository::class => EloquentParseRunRepository::class,
    ];
}
