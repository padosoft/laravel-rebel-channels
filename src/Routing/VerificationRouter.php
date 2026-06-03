<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Routing;

use Illuminate\Contracts\Config\Repository;
use Padosoft\Rebel\Channels\Contracts\VerificationProvider;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Fraud\FraudGuard;
use Padosoft\Rebel\Channels\Results\VerificationResult;
use Padosoft\Rebel\Core\Audit\AuditEvent;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Contracts\AuditLogger;
use Padosoft\Rebel\Core\Contracts\BotProtection;
use Padosoft\Rebel\Core\Contracts\KeyedHasher;
use Padosoft\Rebel\Core\Contracts\RateLimiter;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * The brain of the channels package: turns "send a verification to this number on this
 * channel" into a guarded, audited, fault-tolerant flow:
 *
 *   bot gate -> fraud guard (IRSF) -> per-number rate limit -> provider fallback
 *
 * Each step short-circuits with a generic, machine-readable reason (no code/PII leak),
 * and every decision is written to the Rebel audit trail with the number HMAC'd.
 */
final class VerificationRouter
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly FraudGuard $fraud,
        private readonly BotProtection $bots,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly KeyedHasher $hasher,
        private readonly Repository $config,
    ) {}

    public function start(PhoneIdentifier $phone, Channel $channel, SecurityContext $context, ?string $botToken = null): VerificationResult
    {
        if (! $this->bots->passes($context, $botToken)) {
            return $this->blocked($phone, $channel, 'bot_denied');
        }

        $decision = $this->fraud->inspect($phone, $channel, $context);
        if (! $decision->allowed) {
            return $this->blocked($phone, $channel, $decision->reason ?? 'fraud_blocked');
        }

        $throttleKey = $this->throttleKey($phone, $channel);
        if ($this->limiter->tooManyAttempts($throttleKey, $this->intConfig('rate_limit.max_per_window', 5))) {
            return $this->blocked($phone, $channel, 'rate_limited');
        }

        $providers = $this->orderedProviders($channel);
        if ($providers === []) {
            return $this->blocked($phone, $channel, 'no_provider');
        }

        // Count the attempt UP FRONT: a provider outage (or an attacker forcing failures)
        // must not be a way to bypass the per-number rate limit.
        $this->limiter->hit($throttleKey, $this->intConfig('rate_limit.window_seconds', 3600));

        foreach ($providers as $provider) {
            $result = $provider->start($phone, $channel, $context);

            if (! $result->failed()) {
                $this->recordEvent('channel.verification.started', $phone, $channel, $provider->key(), null);

                return new VerificationResult(
                    $result->status,
                    $provider->key(),
                    $this->packReference($provider->key(), $channel, $result->reference, $phone),
                    null,
                );
            }

            // Provider failed → try the next one (fallback), recording why.
            $this->recordEvent('channel.verification.provider_failed', $phone, $channel, $provider->key(), $result->reason);
        }

        return $this->blocked($phone, $channel, 'all_providers_failed');
    }

    public function check(PhoneIdentifier $phone, string $code, string $reference, SecurityContext $context): VerificationResult
    {
        // The reference is HMAC-signed and bound to THIS phone: a forged or cross-user
        // reference (provider/channel injection, replay) is rejected before we route.
        $unpacked = $this->verifyReference($reference, $phone);
        if ($unpacked === null) {
            $this->recordEvent('channel.verification.failed', $phone, null, null, 'invalid_reference');

            return VerificationResult::fail('invalid_reference');
        }

        [$providerKey, $channel, $providerRef] = $unpacked;
        $provider = $this->providers->get($providerKey);

        if ($provider === null) {
            $this->recordEvent('channel.verification.failed', $phone, $channel, $providerKey, 'unknown_provider');

            return VerificationResult::fail('unknown_provider', $providerKey);
        }

        $result = $provider->check($phone, $code, $providerRef === '' ? null : $providerRef, $context);

        $this->recordEvent(
            $result->approved() ? 'channel.verification.approved' : 'channel.verification.failed',
            $phone,
            $channel,
            $providerKey,
            $result->approved() ? null : ($result->reason ?? 'not_approved'),
        );

        return $result;
    }

    private function blocked(PhoneIdentifier $phone, Channel $channel, string $reason): VerificationResult
    {
        $this->recordEvent('channel.verification.blocked', $phone, $channel, null, $reason);

        return VerificationResult::fail($reason);
    }

    /**
     * @return list<VerificationProvider>
     */
    private function orderedProviders(Channel $channel): array
    {
        $configured = $this->stringList('rebel-channels.providers');

        if ($configured === []) {
            return $this->providers->supporting($channel);
        }

        $ordered = [];
        foreach ($configured as $key) {
            $provider = $this->providers->get($key);
            if ($provider !== null && $provider->supports($channel)) {
                $ordered[] = $provider;
            }
        }

        return $ordered;
    }

    private function throttleKey(PhoneIdentifier $phone, Channel $channel): string
    {
        return 'rebel-channels:send:'.$channel->value.':'.hash('sha256', $phone->normalized());
    }

    /**
     * Build a tamper-evident reference: a pipe-delimited payload (provider, channel,
     * provider-ref, phone hash) plus a keyed HMAC over it. The phone hash binds the
     * reference to one number so it cannot be replayed by another user.
     */
    private function packReference(string $providerKey, Channel $channel, ?string $providerRef, PhoneIdentifier $phone): string
    {
        $payload = implode('|', [
            $providerKey,
            $channel->value,
            $providerRef ?? '',
            $this->phoneHash($phone),
        ]);

        return $payload.'~'.$this->hasher->hash($payload)->hash;
    }

    /**
     * Verify a reference's signature and phone binding. Returns [providerKey, channel,
     * providerRef] when valid, or null when forged/tampered/for another number.
     *
     * @return array{string, Channel, string}|null
     */
    private function verifyReference(string $reference, PhoneIdentifier $phone): ?array
    {
        $separator = strrpos($reference, '~');
        if ($separator === false) {
            return null;
        }

        $payload = substr($reference, 0, $separator);
        $mac = substr($reference, $separator + 1);

        if (! hash_equals($this->hasher->hash($payload)->hash, $mac)) {
            return null;
        }

        $parts = explode('|', $payload);
        if (count($parts) !== 4) {
            return null;
        }

        [$providerKey, $channelValue, $providerRef, $phoneHash] = $parts;

        if (! hash_equals($phoneHash, $this->phoneHash($phone))) {
            return null;
        }

        $channel = Channel::tryFrom($channelValue);
        if ($channel === null) {
            return null;
        }

        return [$providerKey, $channel, $providerRef];
    }

    private function phoneHash(PhoneIdentifier $phone): string
    {
        return hash('sha256', $phone->normalized());
    }

    private function recordEvent(string $type, PhoneIdentifier $phone, ?Channel $channel, ?string $provider, ?string $reason): void
    {
        $hash = $this->hasher->hash($phone->normalized());

        $this->audit->record(new AuditEvent(
            type: $type,
            identifierHmac: $hash->hash,
            keyVersion: $hash->keyVersion,
            channel: $channel?->value,
            provider: $provider,
            metadata: $reason !== null ? ['reason' => $reason] : [],
        ));
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->config->get($key);

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
        $value = $this->config->get("rebel-channels.{$key}", $default);

        return is_int($value) ? $value : $default;
    }
}
