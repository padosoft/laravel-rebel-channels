<?php

declare(strict_types=1);

use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Routing\DeliveryChannelRegistry;
use Padosoft\Rebel\Channels\Testing\FakeMessageDeliveryChannel;

it('registers and resolves a delivery channel by key', function () {
    $registry = new DeliveryChannelRegistry;
    $sms = new FakeMessageDeliveryChannel('twilio', [Channel::Sms]);

    $registry->register($sms);

    expect($registry->get('twilio'))->toBe($sms)
        ->and($registry->has('twilio'))->toBeTrue()
        ->and($registry->has('nope'))->toBeFalse()
        ->and($registry->get('nope'))->toBeNull();
});

it('returns every channel supporting a given Channel, in registration order', function () {
    $registry = new DeliveryChannelRegistry;
    $sms = new FakeMessageDeliveryChannel('twilio', [Channel::Sms]);
    $telegram = new FakeMessageDeliveryChannel('telegram', [Channel::Telegram]);
    $multi = new FakeMessageDeliveryChannel('vonage', [Channel::Sms, Channel::Voice]);

    $registry->register($sms);
    $registry->register($telegram);
    $registry->register($multi);

    expect($registry->supporting(Channel::Sms))->toBe([$sms, $multi])
        ->and($registry->supporting(Channel::Telegram))->toBe([$telegram])
        ->and($registry->supporting(Channel::Voice))->toBe([$multi])
        ->and($registry->supporting(Channel::Discord))->toBe([]);
});

it('exposes all channels and their keys in registration order', function () {
    $registry = new DeliveryChannelRegistry;
    $telegram = new FakeMessageDeliveryChannel('telegram', [Channel::Telegram]);
    $discord = new FakeMessageDeliveryChannel('discord', [Channel::Discord]);

    $registry->register($telegram);
    $registry->register($discord);

    expect($registry->all())->toBe([$telegram, $discord])
        ->and($registry->keys())->toBe(['telegram', 'discord']);
});

it('overwrites a channel registered twice under the same key', function () {
    $registry = new DeliveryChannelRegistry;
    $first = new FakeMessageDeliveryChannel('telegram', [Channel::Telegram]);
    $second = new FakeMessageDeliveryChannel('telegram', [Channel::Telegram]);

    $registry->register($first);
    $registry->register($second);

    expect($registry->all())->toBe([$second])
        ->and($registry->get('telegram'))->toBe($second);
});

it('is bound as a singleton by the service provider', function () {
    expect(app(DeliveryChannelRegistry::class))->toBe(app(DeliveryChannelRegistry::class));
});
