<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Fraud;

use Illuminate\Contracts\Config\Repository;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Contracts\RateLimiter;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * Defences against SMS toll-fraud / IRSF (International Revenue Share Fraud):
 *
 *  - **blocklist**: phone prefixes you never send to;
 *  - **geo allowlist**: when set, ONLY these prefixes are allowed (everything else blocked);
 *  - **per-prefix cap**: a velocity ceiling per coarse number prefix and channel, so an
 *    attacker pumping traffic toward one premium range trips a circuit breaker.
 *
 * All checks operate on the normalized E.164 number; nothing is logged in clear here.
 */
final class FraudGuard
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Repository $config,
    ) {}

    public function inspect(PhoneIdentifier $phone, Channel $channel, SecurityContext $context): FraudDecision
    {
        $normalized = $phone->normalized();

        if ($this->matchesAny($normalized, $this->prefixes('blocked_prefixes'))) {
            return FraudDecision::block('blocked_prefix');
        }

        $allowed = $this->prefixes('allowed_prefixes');
        if ($allowed !== [] && ! $this->matchesAny($normalized, $allowed)) {
            return FraudDecision::block('geo_not_allowed');
        }

        $cap = $this->intConfig('per_prefix.max_per_window', 0);
        if ($cap > 0) {
            $key = 'rebel-channels:prefix:'.$channel->value.':'.$this->coarsePrefix($normalized);

            if ($this->limiter->tooManyAttempts($key, $cap)) {
                return FraudDecision::block('prefix_cap');
            }

            $this->limiter->hit($key, $this->intConfig('per_prefix.window_seconds', 3600));
        }

        return FraudDecision::allow();
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matchesAny(string $normalized, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function coarsePrefix(string $normalized): string
    {
        return substr($normalized, 0, max(1, $this->intConfig('per_prefix.length', 3)));
    }

    /**
     * @return list<string>
     */
    private function prefixes(string $key): array
    {
        $value = $this->config->get("rebel-channels.fraud.{$key}");

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get("rebel-channels.fraud.{$key}", $default);

        return is_int($value) ? $value : $default;
    }
}
