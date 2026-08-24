<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Delegation;

use Padosoft\Iam\Contracts\Support\SubjectRef;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * Host-bound seam of the elevation notifier: rebel-channels knows how to DELIVER
 * to a phone number, but only the host application knows which phone number
 * belongs to the delegating user. Bind an implementation in your app.
 *
 * Return `null` when no verified number is known for the subject — the notifier
 * treats that as a delivery failure (audited, and thrown to the caller so
 * iam-agents can audit `notify_failed`), never as "silently skip".
 */
interface ElevationRecipientResolver
{
    public function resolve(SubjectRef $user): ?PhoneIdentifier;
}
