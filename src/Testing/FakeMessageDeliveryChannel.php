<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Testing;

use Padosoft\Rebel\Channels\Contracts\MessageDeliveryChannel;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Results\DeliveryResult;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * Deterministic {@see MessageDeliveryChannel} for tests: records sent messages and
 * returns a queued result.
 */
final class FakeMessageDeliveryChannel implements MessageDeliveryChannel
{
    /** @var list<array{phone: string, message: string, channel: string}> */
    public array $sent = [];

    /**
     * @param  list<Channel>  $channels
     */
    public function __construct(
        private readonly string $key = 'fake',
        private readonly array $channels = [Channel::Sms],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function supports(Channel $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    public function send(PhoneIdentifier $phone, string $message, Channel $channel, SecurityContext $context): DeliveryResult
    {
        $this->sent[] = ['phone' => $phone->normalized(), 'message' => $message, 'channel' => $channel->value];

        return DeliveryResult::queued($channel, $this->key, 'msg-'.count($this->sent));
    }
}
