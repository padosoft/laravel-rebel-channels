<?php

declare(strict_types=1);

use Padosoft\Iam\Contracts\Delegation\ElevationRequest;
use Padosoft\Iam\Contracts\Support\SubjectRef;
use Padosoft\Rebel\Channels\Contracts\MessageDeliveryChannel;
use Padosoft\Rebel\Channels\Delegation\ChannelElevationNotifier;
use Padosoft\Rebel\Channels\Delegation\ElevationRecipientResolver;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Results\DeliveryResult;
use Padosoft\Rebel\Channels\Routing\DeliveryChannelRegistry;
use Padosoft\Rebel\Channels\Testing\FakeMessageDeliveryChannel;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;
use Padosoft\Rebel\Core\Models\RebelAuthEvent;

function elevationRequest(): ElevationRequest
{
    return new ElevationRequest(
        id: 'elv_01TEST',
        grantId: 'dgr_01TEST',
        user: new SubjectRef('user', '42'),
        agent: new SubjectRef('agent', 'agt_x'),
        agentName: 'Copilot',
        requestedScopes: ['orders:write'],
        reason: 'Serve creare la bozza ordine',
        expiresAt: new DateTimeImmutable('+15 minutes'),
    );
}

function bindRecipient(?string $number = '+393331234567'): void
{
    app()->instance(ElevationRecipientResolver::class, new class($number) implements ElevationRecipientResolver
    {
        public function __construct(private readonly ?string $number) {}

        public function resolve(SubjectRef $user): ?PhoneIdentifier
        {
            return $this->number !== null ? PhoneIdentifier::from($this->number) : null;
        }
    });
}

it('delivers on the first configured channel and audits the delivery', function (): void {
    bindRecipient();
    $fake = new FakeMessageDeliveryChannel('fake', [Channel::Sms]);
    app(DeliveryChannelRegistry::class)->register($fake);

    app(ChannelElevationNotifier::class)->notify(elevationRequest());

    expect($fake->sent)->toHaveCount(1)
        ->and($fake->sent[0]['message'])->toContain('Copilot')
        ->and($fake->sent[0]['message'])->toContain('orders:write')
        ->and($fake->sent[0]['channel'])->toBe('sms');

    $row = RebelAuthEvent::query()->where('event_type', 'channel.elevation.notified')->firstOrFail();
    expect($row->metadata['grant_id'] ?? null)->toBe('dgr_01TEST')
        ->and($row->metadata['elevation_id'] ?? null)->toBe('elv_01TEST');
});

it('falls back to the next configured channel when the first fails, auditing the failed hop', function (): void {
    bindRecipient();
    config()->set('rebel-channels.elevation.channels', ['telegram', 'sms']);

    $failing = new class implements MessageDeliveryChannel
    {
        public function key(): string
        {
            return 'tg-down';
        }

        public function supports(Channel $channel): bool
        {
            return $channel === Channel::Telegram;
        }

        public function send(PhoneIdentifier $phone, string $message, Channel $channel, SecurityContext $context): DeliveryResult
        {
            return DeliveryResult::fail($channel, 'api_down', $this->key());
        }
    };
    $sms = new FakeMessageDeliveryChannel('sms-up', [Channel::Sms]);
    app(DeliveryChannelRegistry::class)->register($failing);
    app(DeliveryChannelRegistry::class)->register($sms);

    app(ChannelElevationNotifier::class)->notify(elevationRequest());

    expect($sms->sent)->toHaveCount(1);
    expect(RebelAuthEvent::query()->where('event_type', 'channel.elevation.channel_failed')->count())->toBe(1)
        ->and(RebelAuthEvent::query()->where('event_type', 'channel.elevation.notified')->value('provider'))->toBe('sms-up');
});

it('throws on total delivery failure — informing failed, the request stays approvable in-app', function (): void {
    bindRecipient();
    config()->set('rebel-channels.elevation.channels', ['sms']);
    // No channel registered at all: nothing can accept the message.

    expect(fn () => app(ChannelElevationNotifier::class)->notify(elevationRequest()))
        ->toThrow(RuntimeException::class);

    expect(RebelAuthEvent::query()->where('event_type', 'channel.elevation.notify_failed')->count())->toBe(1);
});

it('throws when the host resolver knows no verified number for the delegating user', function (): void {
    bindRecipient(null);
    app(DeliveryChannelRegistry::class)->register(new FakeMessageDeliveryChannel);

    expect(fn () => app(ChannelElevationNotifier::class)->notify(elevationRequest()))
        ->toThrow(RuntimeException::class);

    expect(RebelAuthEvent::query()->where('event_type', 'channel.elevation.no_recipient')->count())->toBe(1);
});

it('never leaks the message template into codes: the text is informative and secret-free', function (): void {
    bindRecipient();
    $fake = new FakeMessageDeliveryChannel('fake', [Channel::Sms]);
    app(DeliveryChannelRegistry::class)->register($fake);
    config()->set('rebel-channels.elevation.message', 'Agente :agent chiede :scopes — :reason (entro :expires)');

    app(ChannelElevationNotifier::class)->notify(elevationRequest());

    expect($fake->sent[0]['message'])->toStartWith('Agente Copilot chiede orders:write')
        ->and($fake->sent[0]['message'])->toContain('Serve creare la bozza ordine');
});
