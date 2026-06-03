<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Enums;

/**
 * Lifecycle of a verification (e.g. an SMS/WhatsApp OTP via a provider like Twilio Verify).
 */
enum VerificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
    case Failed = 'failed';
}
