<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Routing;

use Padosoft\Rebel\Channels\Contracts\MessageDeliveryChannel;
use Padosoft\Rebel\Channels\Enums\Channel;

/**
 * Holds the registered message-delivery channels (SMS, WhatsApp, Telegram, Discord, ...)
 * and resolves them by key or by the {@see Channel} they support, in registration order.
 *
 * This is the delivery-side counterpart of {@see ProviderRegistry} (which holds
 * verification providers). Delivery packages (channel-telegram, channel-discord,
 * channel-twilio, ...) register their channel here in `packageBooted()`, so the
 * application and the admin panel can discover every delivery channel uniformly.
 */
final class DeliveryChannelRegistry
{
    /** @var array<string, MessageDeliveryChannel> */
    private array $channels = [];

    public function register(MessageDeliveryChannel $channel): void
    {
        $this->channels[$channel->key()] = $channel;
    }

    public function get(string $key): ?MessageDeliveryChannel
    {
        return $this->channels[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->channels[$key]);
    }

    /**
     * Every delivery channel that can carry the given channel, in registration order.
     *
     * @return list<MessageDeliveryChannel>
     */
    public function supporting(Channel $channel): array
    {
        return array_values(array_filter(
            $this->channels,
            fn (MessageDeliveryChannel $delivery): bool => $delivery->supports($channel),
        ));
    }

    /**
     * @return list<MessageDeliveryChannel>
     */
    public function all(): array
    {
        return array_values($this->channels);
    }

    /**
     * The registered channel keys, in registration order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->channels);
    }
}
