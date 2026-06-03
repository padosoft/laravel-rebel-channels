<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Contracts;

use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Results\VerificationResult;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * A provider that can start and check a phone verification (e.g. Twilio Verify) over
 * one or more channels. Concrete implementations live in provider packages
 * (e.g. padosoft/laravel-rebel-channel-twilio); a fake ships for tests.
 */
interface VerificationProvider
{
    /** Unique key, e.g. 'twilio'. */
    public function key(): string;

    public function supports(Channel $channel): bool;

    /** Start a verification (the provider sends the code). */
    public function start(PhoneIdentifier $phone, Channel $channel, SecurityContext $context): VerificationResult;

    /** Check a code the user entered, using the reference returned by start(). */
    public function check(PhoneIdentifier $phone, string $code, ?string $reference, SecurityContext $context): VerificationResult;
}
