# Changelog

All notable changes to `padosoft/laravel-rebel-channels` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.2] - 2026-08-24

### Added
- **`ChannelElevationNotifier`** (`src/Delegation/`): out-of-band notifier for IAM JIT scope
  elevation (`laravel-iam-agents` ≥ 1.1) — implements the `ElevationNotifier` contract from
  `padosoft/laravel-iam-contracts` (^1.4, dev/suggest) with multi-channel fallback in the order
  configured under `rebel-channels.elevation.channels`. The message informs only (secret-free,
  configurable template); approval always happens through the in-app step-up re-consent. Total
  delivery failure throws (the caller audits `notify_failed`); every hop is audited
  (`channel.elevation.notified` / `channel_failed` / `notify_failed` / `no_recipient`) with the
  number HMAC'd.
- **`ElevationRecipientResolver`** contract: the host-bound user→phone seam (only the app knows
  the delegating user's verified number; `null` = failure, never a silent skip).
- `elevation` config section (`channels` fallback order, `message` template with `:agent`,
  `:scopes`, `:reason`, `:expires` placeholders).
- `release` workflow (workflow_dispatch tag + GitHub release, ecosystem pattern).

## [0.1.1] - 2026-06-04

### Added
- `Channel::Telegram` and `Channel::Discord` cases, so the Telegram/Discord delivery providers can register and route through the channels router.

## [0.1.0] - 2026-06-03

### Added
- **`VerificationRouter`**: guarded, audited, fault-tolerant verification flow —
  bot gate → fraud guard → per-number rate limit → provider fallback.
- **Tamper-evident references**: the handle returned by `start()` is HMAC-signed and
  bound to the phone number, so a forged/cross-user reference is rejected on `check()`.
- **`FraudGuard`** (anti toll-fraud / IRSF): prefix blocklist, geo allowlist, and a
  per-prefix velocity circuit breaker.
- **Contracts**: `VerificationProvider`, `MessageDeliveryChannel` (+ `ProviderRegistry`).
- **Results / enums**: `VerificationResult`, `DeliveryResult`, `Channel`,
  `VerificationStatus`, `DeliveryStatus`.
- **Safe defaults**: cache-backed `RateLimiter` (atomic fixed window) and a no-op
  `BotProtection`, bound only when the app hasn't provided its own.
- **Test fakes**: `FakeVerificationProvider`, `FakeMessageDeliveryChannel`.
- Audit of every routing decision with the phone number HMAC'd (no plaintext PII).
- Config file, CI matrix (PHP 8.3/8.4/8.5 × Laravel 12/13), Pest suite, PHPStan level max, Pint.

[Unreleased]: https://github.com/padosoft/laravel-rebel-channels/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/padosoft/laravel-rebel-channels/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/padosoft/laravel-rebel-channels/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/padosoft/laravel-rebel-channels/releases/tag/v0.1.0
