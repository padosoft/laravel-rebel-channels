# Laravel Rebel — Channels

> **One safe, fault-tolerant pipe for phone verifications (SMS / WhatsApp / voice).** You ask "verify this number"; Rebel Channels runs it through a bot gate, anti toll-fraud/IRSF defences, a per-number rate limit, and **provider fallback** — then audits every decision (number always HMAC'd). It is provider-agnostic: plug in `laravel-rebel-channel-twilio` (or your own). Part of the `padosoft/laravel-rebel-*` suite.

<p align="center">
  <img src="resources/screenshoots/Laravel-Rebel-banner.png" alt="Laravel Rebel" width="100%">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 12|13">
  <img src="https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/PHPStan-max-2A6FDB?style=flat-square" alt="PHPStan max">
  <img src="https://img.shields.io/badge/tests-Pest%204-22C55E?style=flat-square" alt="Pest 4">
  <img src="https://img.shields.io/badge/anti--IRSF-built--in-8B5CF6?style=flat-square" alt="anti IRSF">
  <img src="https://img.shields.io/badge/license-MIT-blue?style=flat-square" alt="MIT">
</p>

---

## Table of contents

- [What it is (and what it is not)](#what-it-is-and-what-it-is-not)
- [Quick glossary (one minute)](#quick-glossary-one-minute)
- [Why Rebel Channels — the moats](#why-rebel-channels--the-moats)
- [Rebel Channels vs the alternatives](#rebel-channels-vs-the-alternatives)
- [How it works (step by step)](#how-it-works-step-by-step)
- [Installation (junior-proof)](#installation-junior-proof)
- [Configuration (every option)](#configuration-every-option)
- [Usage examples](#usage-examples)
- [`.env.example`](#envexample)
- [Security notes](#security-notes)
- [Testing & License](#testing--license)

---

## What it is (and what it is not)

**It is** the *routing + defence* layer for sending one-time phone verifications. It does
not talk to Twilio (or any vendor) itself — it defines the contracts and the guarded flow,
and delegates the actual send to a **provider** package such as
[`laravel-rebel-channel-twilio`](https://github.com/padosoft/laravel-rebel-channel-twilio).

**It is not** an OTP generator (that's the provider's job, e.g. Twilio Verify), and it is
not tied to one vendor — register several providers and it will **fall back** between them.

Depends on [`padosoft/laravel-rebel-core`](https://github.com/padosoft/laravel-rebel-core).

---

## Quick glossary (one minute)

| Term | In plain words |
|---|---|
| **Verification** | "Send a code to this number and let me check what the user typed." |
| **Provider** | The vendor that actually sends/checks the code (e.g. Twilio Verify). |
| **Channel** | The medium: `sms`, `whatsapp`, `voice`. |
| **Fallback** | If provider A is down, automatically try provider B. |
| **IRSF / toll-fraud** | International Revenue Share Fraud: attackers pump OTP traffic toward premium-rate numbers to cash in. Expensive if undefended. |
| **Geo allowlist** | "Only send to these country prefixes" — the single most effective IRSF defence. |
| **Per-prefix cap** | A velocity circuit breaker per number prefix, so a sudden spike toward one range trips. |
| **Bot gate** | A check (reCAPTCHA/Turnstile…) that a human, not a script, triggered the send. |

---

## Why Rebel Channels — the moats

| ★ | What | In short |
|---|---|---|
| ★★★ | **IRSF / toll-fraud defences built in** | Geo allowlist, prefix blocklist, and a per-prefix velocity circuit breaker — the stuff that saves real money. |
| ★★★ | **Provider fallback** | Register Twilio + a backup; an outage on one silently rolls over to the next. |
| ★★★ | **Tamper-evident references** | The `check()` handle is HMAC-signed and **bound to the phone** — no provider/channel injection, no cross-user replay. |
| ★★ | **Bot gate + per-number rate limit** | Two more layers before a single euro is spent on a send. |
| ★★ | **Audited, privacy-first** | Every decision is recorded with the number **HMAC'd** (never in clear). |
| ★★ | **Vendor-agnostic** | Swap or combine providers without touching your app code. |
| ★ | **Safe defaults** | Ships a cache-backed rate limiter and a no-op bot gate so it just works, then hardens as you configure it. |

---

## Rebel Channels vs the alternatives

Sending a verification SMS, compared:

| Capability | **Rebel Channels** | Shopify | Twilio Verify SDK (direct) | Hand-rolled SMS + OTP |
|---|:---:|:---:|:---:|:---:|
| Send/check a code | ✅ | ✅ | ✅ | ✅ |
| **Provider fallback** on outage | ✅ | ❌ | ❌ | ❌ |
| Geo allowlist (IRSF) | ✅ | ❌ | ➖ (manual in console) | ❌ |
| Per-prefix velocity circuit breaker | ✅ | ❌ | ❌ | ❌ |
| Per-number rate limit / cooldown | ✅ | ➖ | ➖ | ❌ |
| Bot gate before spending | ✅ | ➖ | ❌ | ❌ |
| Reference signed + phone-bound (anti replay/injection) | ✅ | ❌ | ❌ | ❌ |
| Unified audit trail, number HMAC'd | ✅ | ❌ | ❌ | ❌ |
| Vendor-agnostic / swappable | ✅ | ❌ | ❌ | ➖ |

> Legend: ✅ built-in · ➖ partial / manual / hosted-only · ❌ not available. Twilio Verify is a great
> provider — Rebel Channels wraps it (and others) with the routing, fraud and audit layer
> your app would otherwise have to build and maintain itself.
> Shopify is a closed, hosted commerce platform: it sends its own customer OTPs but exposes
> none of these low-level primitives to you — you can't self-host it, swap providers, or
> configure its fraud controls, so it's a black box for this use case.

---

## How it works (step by step)

```
$router->start($phone, Channel::Sms, $context, $botToken)
        |
        v
[1] Bot gate        -> BotProtection::passes()?      no -> blocked('bot_denied')
[2] Fraud guard     -> blocklist / geo allowlist / per-prefix cap?  no -> blocked(reason)
[3] Rate limit      -> too many sends to this number?  yes -> blocked('rate_limited')
        |  (the attempt is counted here, BEFORE trying providers, so outages can't bypass it)
        v
[4] Provider fallback -> try providers in order; first that accepts wins
        |
        v
returns a PENDING result with a SIGNED, phone-bound reference

...later...

$router->check($phone, $code, $reference, $context)
        |
        v
verify the reference signature + phone binding -> delegate to the provider -> approved / failed
```

---

## Installation (junior-proof)

> Prerequisites: Laravel **12 or 13**, PHP **8.3+**, and `padosoft/laravel-rebel-core`.
> You also want at least one provider, e.g. `padosoft/laravel-rebel-channel-twilio`.

```bash
composer require padosoft/laravel-rebel-channels
php artisan vendor:publish --tag="rebel-channels-config"
```

Then register a provider (the Twilio package does this for you) and you're ready:

```php
use Padosoft\Rebel\Channels\Enums\Channel;
use Padosoft\Rebel\Channels\Routing\VerificationRouter;
use Padosoft\Rebel\Core\Context\SecurityContext;
use Padosoft\Rebel\Core\Identifiers\PhoneIdentifier;

$router = app(VerificationRouter::class);
$start  = $router->start(PhoneIdentifier::from('+39 333 1234567'), Channel::Sms, SecurityContext::fromRequest($request));
```

---

## Configuration (every option)

File `config/rebel-channels.php`:

| Key | Default | What it does |
|---|---|---|
| `providers` | `[]` | Provider keys to try, in fallback order. Empty = every registered provider that supports the channel. |
| `rate_limit.max_per_window` | `5` | Max verification sends per phone+channel within the window. |
| `rate_limit.window_seconds` | `3600` | The rate-limit window length. |
| `fraud.allowed_prefixes` | `[]` | If non-empty, ONLY numbers starting with one of these E.164 prefixes are allowed (geo allowlist). |
| `fraud.blocked_prefixes` | `[]` | Numbers starting with one of these are always blocked. |
| `fraud.per_prefix.length` | `3` | How many leading chars of the E.164 number form the velocity bucket (`3` → `+39`). |
| `fraud.per_prefix.max_per_window` | `0` | Per-prefix send cap (`0` disables the circuit breaker). |
| `fraud.per_prefix.window_seconds` | `3600` | The per-prefix window length. |

To actually gate bots, bind your own implementation of the core `BotProtection` contract
(reCAPTCHA/Turnstile…); otherwise a permissive no-op default is used.

---

## Usage examples

**1. Start + check a verification**

```php
$start = $router->start($phone, Channel::Sms, $ctx);
// store $start->reference (already signed) for the check step

$result = $router->check($phone, $request->string('code'), $reference, $ctx);
if ($result->approved()) {
    // the number is verified
}
```

**2. WhatsApp with SMS fallback** — list both providers; the router rolls over:

```php
// config/rebel-channels.php
'providers' => ['twilio', 'vonage'],
```

**3. Lock down to your markets (kills most IRSF)**

```php
'fraud' => [
    'allowed_prefixes' => ['+39', '+1', '+44'], // only Italy, US, UK
    'per_prefix' => ['length' => 3, 'max_per_window' => 50, 'window_seconds' => 3600],
],
```

**4. Pass a bot token** (verified by your bound `BotProtection`):

```php
$router->start($phone, Channel::Sms, $ctx, $request->string('captcha_token'));
```

**5. Inspect the audit trail**

```php
use Padosoft\Rebel\Core\Models\RebelAuthEvent;

RebelAuthEvent::query()->where('event_type', 'channel.verification.blocked')->get(); // see WHY sends were stopped
```

---

## `.env.example`

```dotenv
REBEL_CHANNELS_RL_MAX=5
REBEL_CHANNELS_RL_WINDOW=3600
REBEL_CHANNELS_PREFIX_LEN=3
REBEL_CHANNELS_PREFIX_MAX=0
REBEL_CHANNELS_PREFIX_WINDOW=3600
```

---

## Security notes

- **IRSF defence in depth**: geo allowlist + prefix blocklist + per-prefix velocity cap.
- **Rate limit can't be bypassed**: the attempt is counted *before* providers are tried, so
  forcing provider failures does not grant unlimited sends.
- **Tamper-evident, phone-bound references**: the `check()` handle is HMAC-signed and tied to
  the number; forged or cross-user references are rejected.
- **Privacy-first audit**: every routing decision is recorded with the phone number HMAC'd;
  reasons are generic machine codes, never the OTP.
- **Atomic rate limiting**: the default limiter uses the cache's atomic increment with a TTL
  set once per window (no slide, no under-count).

---

## 🔋 Vibe coding with batteries included

This package ships **AI batteries** — so you (and your AI agent) can extend it correctly on the
first try:

- **`CLAUDE.md`** — a concise AI working guide (purpose, conventions, architecture, how to extend,
  Definition of Done). Plain Markdown, so Claude Code, Cursor, Copilot and Codex all read it.
- **`AGENTS.md`** — the agent/workflow contract (branch → PR → CI → tag/release, the gates).
- **`.claude/skills/`** — invocable skills (at least `rebel-package-dev`) encoding the suite's
  TDD loop, the **PHPStan-level-max** recipes, the security/telemetry rules, and the release
  discipline.

Open the repo in your AI editor and just start — the rules, guardrails and extension recipes come
with it. PRs that follow the shipped `CLAUDE.md` pass CI (PHPStan max + Pest + Pint) and review the
first time around.

## Testing & License

```bash
composer test      # Pest (router fallback, rate limit, bot gate, fraud guard, signed references, audit)
composer phpstan   # static analysis, level max
composer pint      # code style
```

**License:** MIT — see [LICENSE](LICENSE). Part of the [`padosoft/laravel-rebel`](https://github.com/padosoft) suite.
