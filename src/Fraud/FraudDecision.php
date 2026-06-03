<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Fraud;

/**
 * The outcome of a fraud inspection: whether a verification/message may proceed, and
 * (if not) a machine-readable reason.
 */
final readonly class FraudDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function block(string $reason): self
    {
        return new self(false, $reason);
    }
}
