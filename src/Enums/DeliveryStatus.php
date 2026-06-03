<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Enums;

/**
 * Delivery status of a sent message, mirroring typical provider webhooks
 * (queued → sent → delivered, or failed/undelivered).
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Undelivered = 'undelivered';
}
