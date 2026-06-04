<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels;

use Padosoft\Rebel\Channels\Fraud\FraudGuard;
use Padosoft\Rebel\Channels\Routing\DeliveryChannelRegistry;
use Padosoft\Rebel\Channels\Routing\ProviderRegistry;
use Padosoft\Rebel\Channels\Routing\VerificationRouter;
use Padosoft\Rebel\Channels\Support\CacheRateLimiter;
use Padosoft\Rebel\Channels\Support\NullBotProtection;
use Padosoft\Rebel\Core\Contracts\BotProtection;
use Padosoft\Rebel\Core\Contracts\RateLimiter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Channel/provider abstraction for Laravel Rebel: verification routing with fallback,
 * cooldown, multi-dimensional rate limiting and anti toll-fraud/IRSF defences.
 *
 * Provides safe defaults for the RateLimiter (cache-backed) and BotProtection (no-op)
 * contracts when the application has not bound its own.
 */
final class RebelChannelsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rebel-channels')
            ->hasConfigFile('rebel-channels');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(DeliveryChannelRegistry::class);
        $this->app->singleton(FraudGuard::class);
        $this->app->singleton(VerificationRouter::class);

        if (! $this->app->bound(RateLimiter::class)) {
            $this->app->singleton(RateLimiter::class, CacheRateLimiter::class);
        }

        if (! $this->app->bound(BotProtection::class)) {
            $this->app->singleton(BotProtection::class, NullBotProtection::class);
        }
    }
}
