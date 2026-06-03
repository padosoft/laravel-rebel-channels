<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Support;

use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Contracts\BotProtection;

/**
 * No-op {@see BotProtection} default: it always passes. Bind your own implementation
 * (reCAPTCHA, Turnstile, hCaptcha…) to the BotProtection contract to actually gate bots.
 * It exists so the router has a safe default and does not hard-fail when no bot
 * protection is configured.
 */
final class NullBotProtection implements BotProtection
{
    public function passes(SecurityContext $context, ?string $token): bool
    {
        return true;
    }
}
