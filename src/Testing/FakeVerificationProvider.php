<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Testing;

use Padosoft\Rebel\Channels\Contracts\VerificationProvider;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Results\VerificationResult;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * Deterministic {@see VerificationProvider} for tests: configurable key, supported
 * channels, a fixed expected code, and a "healthy" flag to simulate provider outages
 * (so the router's fallback can be exercised).
 *
 * @phpstan-type StartedCall array{phone: string, channel: string}
 */
final class FakeVerificationProvider implements VerificationProvider
{
    /** @var list<array{phone: string, channel: string}> */
    public array $started = [];

    /**
     * @param  list<Channel>  $channels
     */
    public function __construct(
        private readonly string $key = 'fake',
        private readonly array $channels = [Channel::Sms],
        private readonly string $expectedCode = '123456',
        private readonly bool $healthy = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function supports(Channel $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    public function start(PhoneIdentifier $phone, Channel $channel, SecurityContext $context): VerificationResult
    {
        if (! $this->healthy) {
            return VerificationResult::fail('provider_unavailable', $this->key);
        }

        $this->started[] = ['phone' => $phone->normalized(), 'channel' => $channel->value];

        return VerificationResult::started($this->key, 'ref-'.count($this->started));
    }

    public function check(PhoneIdentifier $phone, string $code, ?string $reference, SecurityContext $context): VerificationResult
    {
        return hash_equals($this->expectedCode, $code)
            ? VerificationResult::approve($this->key)
            : VerificationResult::deny($this->key, 'wrong_code');
    }
}
