<?php

declare(strict_types=1);

use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Fraud\FraudGuard;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

function guard(): FraudGuard
{
    return app(FraudGuard::class);
}

it('blocks a blocked prefix', function (): void {
    config()->set('rebel-channels.fraud.blocked_prefixes', ['+225']);

    $decision = guard()->inspect(PhoneIdentifier::from('+22501020304'), Channel::Sms, new SecurityContext('r'));

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reason)->toBe('blocked_prefix');
});

it('enforces a geo allowlist when set', function (): void {
    config()->set('rebel-channels.fraud.allowed_prefixes', ['+39', '+1']);

    expect(guard()->inspect(PhoneIdentifier::from('+393331234567'), Channel::Sms, new SecurityContext('r'))->allowed)->toBeTrue()
        ->and(guard()->inspect(PhoneIdentifier::from('+447700900123'), Channel::Sms, new SecurityContext('r'))->reason)->toBe('geo_not_allowed');
});

it('trips a per-prefix velocity cap (IRSF circuit breaker)', function (): void {
    config()->set('rebel-channels.fraud.per_prefix', ['length' => 3, 'max_per_window' => 2, 'window_seconds' => 3600]);

    // Two different numbers sharing the same coarse prefix +39.
    expect(guard()->inspect(PhoneIdentifier::from('+393331111111'), Channel::Sms, new SecurityContext('r'))->allowed)->toBeTrue()
        ->and(guard()->inspect(PhoneIdentifier::from('+393332222222'), Channel::Sms, new SecurityContext('r'))->allowed)->toBeTrue()
        ->and(guard()->inspect(PhoneIdentifier::from('+393333333333'), Channel::Sms, new SecurityContext('r'))->reason)->toBe('prefix_cap');
});

it('allows by default with no rules configured', function (): void {
    expect(guard()->inspect(PhoneIdentifier::from('+393331234567'), Channel::Sms, new SecurityContext('r'))->allowed)->toBeTrue();
});
