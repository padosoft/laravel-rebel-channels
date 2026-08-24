<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Delegation;

use Illuminate\Contracts\Config\Repository;
use Padosoft\Iam\Contracts\Delegation\ElevationNotifier;
use Padosoft\Iam\Contracts\Delegation\ElevationRequest;
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Routing\DeliveryChannelRegistry;
use Padosoft\Rebel\Core\Audit\AuditEvent;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Contracts\AuditLogger;
use Padosoft\Rebel\Core\Contracts\KeyedHasher;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

/**
 * Out-of-band notifier for IAM JIT scope elevation (laravel-iam-agents ≥ 1.1):
 * when an agent asks for scopes outside its delegation grant, the delegating
 * human gets a nudge on their own channels — SMS, WhatsApp, Telegram, Discord,
 * voice — with multi-channel fallback in the order configured under
 * `rebel-channels.elevation.channels`.
 *
 * Boundaries, non-negotiable:
 *  - the message INFORMS only. It never carries codes, links with secrets, or
 *    anything that could approve the elevation: approval happens exclusively
 *    through the step-up re-consent in the app (iam-agents self-service);
 *  - delivery is attempted channel by channel, first accepted result wins;
 *    every failed hop is audited (`channel.elevation.channel_failed`);
 *  - TOTAL failure throws — the caller (iam-agents) audits `notify_failed` and
 *    the request stays approvable in self-service. Informing failed; nothing
 *    about the authorization state changed;
 *  - the recipient comes from the host-bound {@see ElevationRecipientResolver}:
 *    an unknown recipient is a failure, not a silent skip.
 *
 * Wire it in the host app:
 *   config('iam-agents.elevation.notifier') = ChannelElevationNotifier::class
 *   bind ElevationRecipientResolver → your user→phone lookup
 */
final class ChannelElevationNotifier implements ElevationNotifier
{
    public function __construct(
        private readonly DeliveryChannelRegistry $registry,
        private readonly ElevationRecipientResolver $recipients,
        private readonly AuditLogger $audit,
        private readonly KeyedHasher $hasher,
        private readonly Repository $config,
    ) {}

    public function notify(ElevationRequest $request): void
    {
        $phone = $this->recipients->resolve($request->user);
        if ($phone === null) {
            $this->record('channel.elevation.no_recipient', null, null, $request, 'unknown_recipient');

            throw new \RuntimeException('Nessun recapito verificato per il delegante: notifica di elevazione non consegnabile.');
        }

        $channels = $this->configuredChannels();
        if ($channels === []) {
            $this->record('channel.elevation.notify_failed', $phone, null, $request, 'no_channels_configured');

            throw new \RuntimeException('Nessun canale configurato in rebel-channels.elevation.channels.');
        }

        $message = $this->message($request);
        $context = new SecurityContext('elv-'.$request->id);

        foreach ($channels as $channel) {
            foreach ($this->registry->supporting($channel) as $delivery) {
                $result = $delivery->send($phone, $message, $channel, $context);

                if ($result->accepted()) {
                    $this->record('channel.elevation.notified', $phone, $channel, $request, null, $delivery->key());

                    return;
                }

                $this->record('channel.elevation.channel_failed', $phone, $channel, $request, $result->reason ?? 'failed', $delivery->key());
            }
        }

        $this->record('channel.elevation.notify_failed', $phone, null, $request, 'all_channels_failed');

        throw new \RuntimeException('Nessun canale ha accettato la notifica di elevazione.');
    }

    /**
     * The configured fallback order, invalid entries skipped (fail-closed on the
     * single entry, not on the whole list — a typo must not silence the others).
     *
     * @return list<Channel>
     */
    private function configuredChannels(): array
    {
        $raw = $this->config->get('rebel-channels.elevation.channels', ['sms']);
        if (! is_array($raw)) {
            return [];
        }

        $channels = [];
        foreach ($raw as $value) {
            $channel = is_string($value) ? Channel::tryFrom($value) : null;
            if ($channel !== null) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * Secret-free, self-contained text. The template is configurable
     * (`rebel-channels.elevation.message`) but the placeholders stay the only
     * dynamic parts — nothing the agent wrote reaches the message except the
     * reason, which the delegante will re-read on the consent screen anyway.
     */
    private function message(ElevationRequest $request): string
    {
        $template = $this->config->get('rebel-channels.elevation.message');
        if (! is_string($template) || $template === '') {
            $template = 'L\'agente ":agent" chiede permessi aggiuntivi (:scopes): :reason. '
                .'Approva o nega dalla pagina delle tue deleghe entro :expires. '
                .'Nessun codice ti verrà mai chiesto via messaggio.';
        }

        return strtr($template, [
            ':agent' => $request->agentName,
            ':scopes' => implode(', ', $request->requestedScopes),
            ':reason' => $request->reason,
            ':expires' => $request->expiresAt->format('H:i (e)'),
        ]);
    }

    private function record(string $type, ?PhoneIdentifier $phone, ?Channel $channel, ElevationRequest $request, ?string $reason, ?string $provider = null): void
    {
        $hash = $phone !== null ? $this->hasher->hash($phone->normalized()) : null;

        $this->audit->record(new AuditEvent(
            type: $type,
            subjectType: $request->user->type,
            subjectId: $request->user->id,
            identifierHmac: $hash?->hash,
            keyVersion: $hash?->keyVersion,
            channel: $channel?->value,
            provider: $provider,
            purpose: 'iam-delegation-elevation',
            metadata: array_filter([
                'elevation_id' => $request->id,
                'grant_id' => $request->grantId,
                'agent' => $request->agent->id,
                'reason' => $reason,
            ], static fn (?string $v): bool => $v !== null),
        ));
    }
}
