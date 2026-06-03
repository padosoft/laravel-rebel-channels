<?php

declare(strict_types=1);

use Padosoft\Rebel\Channels\Contracts\VerificationProvider;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Results\VerificationResult;
use Padosoft\Rebel\Channels\Routing\ProviderRegistry;
use Padosoft\Rebel\Channels\Routing\VerificationRouter;
use Padosoft\Rebel\Channels\Testing\FakeVerificationProvider;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Contracts\BotProtection;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;
use Padosoft\Rebel\Core\Models\RebelAuthEvent;

function phone(string $number = '+393331234567'): PhoneIdentifier
{
    return PhoneIdentifier::from($number);
}

function ctx(): SecurityContext
{
    return new SecurityContext('req-1');
}

it('starts a verification and approves the correct code', function (): void {
    app(ProviderRegistry::class)->register(new FakeVerificationProvider('fake', [Channel::Sms], '123456'));
    $router = app(VerificationRouter::class);

    $start = $router->start(phone(), Channel::Sms, ctx());
    expect($start->pending())->toBeTrue()
        ->and($start->provider)->toBe('fake');

    expect($router->check(phone(), '123456', (string) $start->reference, ctx())->approved())->toBeTrue()
        ->and($router->check(phone(), '000000', (string) $start->reference, ctx())->failed())->toBeTrue();
});

it('falls back to the next provider when the first is unavailable', function (): void {
    $registry = app(ProviderRegistry::class);
    $registry->register(new FakeVerificationProvider('down', [Channel::Sms], '123456', healthy: false));
    $up = new FakeVerificationProvider('up', [Channel::Sms], '123456');
    $registry->register($up);
    config()->set('rebel-channels.providers', ['down', 'up']);

    $start = app(VerificationRouter::class)->start(phone(), Channel::Sms, ctx());

    expect($start->pending())->toBeTrue()
        ->and($start->provider)->toBe('up')
        ->and($up->started)->toHaveCount(1);
});

it('rate-limits repeated sends to the same number', function (): void {
    app(ProviderRegistry::class)->register(new FakeVerificationProvider('fake', [Channel::Sms]));
    config()->set('rebel-channels.rate_limit.max_per_window', 2);
    $router = app(VerificationRouter::class);

    expect($router->start(phone(), Channel::Sms, ctx())->pending())->toBeTrue()
        ->and($router->start(phone(), Channel::Sms, ctx())->pending())->toBeTrue()
        ->and($router->start(phone(), Channel::Sms, ctx())->reason)->toBe('rate_limited');
});

it('rejects a forged or cross-phone reference', function (): void {
    app(ProviderRegistry::class)->register(new FakeVerificationProvider('fake', [Channel::Sms], '123456'));
    $router = app(VerificationRouter::class);
    $start = $router->start(phone('+393331234567'), Channel::Sms, ctx());

    expect($router->check(phone('+393331234567'), '123456', 'fake|sms|ref-1', ctx())->reason)->toBe('invalid_reference')
        // Valid signature but a different phone must not be accepted (no cross-user replay).
        ->and($router->check(phone('+393339999999'), '123456', (string) $start->reference, ctx())->reason)->toBe('invalid_reference');
});

it('counts the attempt even when every provider fails (no rate-limit bypass)', function (): void {
    app(ProviderRegistry::class)->register(new FakeVerificationProvider('down', [Channel::Sms], '123456', healthy: false));
    config()->set('rebel-channels.rate_limit.max_per_window', 2);
    $router = app(VerificationRouter::class);

    expect($router->start(phone(), Channel::Sms, ctx())->reason)->toBe('all_providers_failed')
        ->and($router->start(phone(), Channel::Sms, ctx())->reason)->toBe('all_providers_failed')
        ->and($router->start(phone(), Channel::Sms, ctx())->reason)->toBe('rate_limited');
});

it('preserves a provider reference that contains the delimiter character', function (): void {
    app(ProviderRegistry::class)->register(new class implements VerificationProvider
    {
        public function key(): string
        {
            return 'pipe';
        }

        public function supports(Channel $channel): bool
        {
            return true;
        }

        public function start(PhoneIdentifier $phone, Channel $channel, SecurityContext $context): VerificationResult
        {
            return VerificationResult::started('pipe', 'a|b|c');
        }

        public function check(PhoneIdentifier $phone, string $code, ?string $reference, SecurityContext $context): VerificationResult
        {
            // The provider must receive its reference intact, '|' included.
            return $reference === 'a|b|c' ? VerificationResult::approve('pipe') : VerificationResult::deny('pipe', 'bad_ref');
        }
    });

    $router = app(VerificationRouter::class);
    $start = $router->start(phone(), Channel::Sms, ctx());

    expect($router->check(phone(), 'x', (string) $start->reference, ctx())->approved())->toBeTrue();
});

it('blocks when bot protection fails', function (): void {
    app()->instance(BotProtection::class, new class implements BotProtection
    {
        public function passes(SecurityContext $context, ?string $token): bool
        {
            return false;
        }
    });
    app(ProviderRegistry::class)->register(new FakeVerificationProvider('fake', [Channel::Sms]));

    $result = app(VerificationRouter::class)->start(phone(), Channel::Sms, ctx(), 'token');

    expect($result->failed())->toBeTrue()
        ->and($result->reason)->toBe('bot_denied');
});

it('audits the send with the number HMAC-ed (no plaintext)', function (): void {
    app(ProviderRegistry::class)->register(new FakeVerificationProvider('fake', [Channel::Sms]));
    app(VerificationRouter::class)->start(phone('+393331234567'), Channel::Sms, ctx());

    $row = RebelAuthEvent::query()->where('event_type', 'channel.verification.started')->first();
    expect($row)->not->toBeNull()
        ->and($row?->channel)->toBe('sms')
        ->and($row?->provider)->toBe('fake')
        ->and($row?->identifier_hmac)->not->toBeNull()
        ->and($row?->identifier_hmac)->not->toContain('393331234567');
});
