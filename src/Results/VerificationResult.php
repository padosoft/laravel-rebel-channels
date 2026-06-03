<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Results;

use Padosoft\Rebel\Channels\Enums\VerificationStatus;

/**
 * Outcome of starting or checking a verification.
 *
 *  - `provider`  identifies which provider handled it;
 *  - `reference` is an opaque handle (e.g. the provider's verification SID) to pass back on check;
 *  - `reason`    is a machine-readable failure reason (never leaks the code).
 */
final readonly class VerificationResult
{
    public function __construct(
        public VerificationStatus $status,
        public ?string $provider = null,
        public ?string $reference = null,
        public ?string $reason = null,
    ) {}

    public function approved(): bool
    {
        return $this->status === VerificationStatus::Approved;
    }

    public function pending(): bool
    {
        return $this->status === VerificationStatus::Pending;
    }

    /** A terminal non-success state (failed, denied or expired). */
    public function failed(): bool
    {
        return in_array(
            $this->status,
            [VerificationStatus::Failed, VerificationStatus::Denied, VerificationStatus::Expired],
            true,
        );
    }

    public static function started(string $provider, ?string $reference = null): self
    {
        return new self(VerificationStatus::Pending, $provider, $reference);
    }

    public static function approve(string $provider): self
    {
        return new self(VerificationStatus::Approved, $provider);
    }

    public static function deny(string $provider, string $reason = 'denied'): self
    {
        return new self(VerificationStatus::Denied, $provider, null, $reason);
    }

    public static function fail(string $reason, ?string $provider = null): self
    {
        return new self(VerificationStatus::Failed, $provider, null, $reason);
    }
}
