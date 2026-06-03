<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Routing;

use Padosoft\Rebel\Channels\Contracts\VerificationProvider;
use Padosoft\Rebel\Channels\Enums\Channel;

/**
 * Holds the registered verification providers and resolves them by key or by the
 * channel they support (in registration order).
 */
final class ProviderRegistry
{
    /** @var array<string, VerificationProvider> */
    private array $providers = [];

    public function register(VerificationProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): ?VerificationProvider
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * @return list<VerificationProvider>
     */
    public function supporting(Channel $channel): array
    {
        return array_values(array_filter(
            $this->providers,
            fn (VerificationProvider $provider): bool => $provider->supports($channel),
        ));
    }

    /**
     * @return list<VerificationProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
