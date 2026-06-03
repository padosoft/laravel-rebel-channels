<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Contracts;

use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Results\DeliveryResult;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * A channel that can deliver an arbitrary message (not necessarily a verification),
 * e.g. an SMS or a WhatsApp template message.
 */
interface MessageDeliveryChannel
{
    /** Unique key, e.g. 'twilio'. */
    public function key(): string;

    public function supports(Channel $channel): bool;

    public function send(PhoneIdentifier $phone, string $message, Channel $channel, SecurityContext $context): DeliveryResult;
}
