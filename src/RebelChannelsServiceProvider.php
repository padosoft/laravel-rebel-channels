<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Skeleton iniziale di padosoft/laravel-rebel-channels. Implementazione in arrivo.
 */
final class RebelChannelsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-rebel-channels');
    }
}
