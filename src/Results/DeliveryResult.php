<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Results;

use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Enums\DeliveryStatus;

/**
 * Outcome of sending a message over a delivery channel.
 */
final readonly class DeliveryResult
{
    public function __construct(
        public DeliveryStatus $status,
        public Channel $channel,
        public ?string $provider = null,
        public ?string $reference = null,
        public ?string $reason = null,
    ) {}

    public function accepted(): bool
    {
        return in_array(
            $this->status,
            [DeliveryStatus::Queued, DeliveryStatus::Sent, DeliveryStatus::Delivered],
            true,
        );
    }

    public function failed(): bool
    {
        return in_array($this->status, [DeliveryStatus::Failed, DeliveryStatus::Undelivered], true);
    }

    public static function queued(Channel $channel, string $provider, ?string $reference = null): self
    {
        return new self(DeliveryStatus::Queued, $channel, $provider, $reference);
    }

    public static function sent(Channel $channel, string $provider, ?string $reference = null): self
    {
        return new self(DeliveryStatus::Sent, $channel, $provider, $reference);
    }

    public static function fail(Channel $channel, string $reason, ?string $provider = null): self
    {
        return new self(DeliveryStatus::Failed, $channel, $provider, null, $reason);
    }
}
