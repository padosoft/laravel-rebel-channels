<?php

declare(strict_types=1);

namespace Padosoft\Rebel\Channels\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Padosoft\Rebel\Core\Contracts\RateLimiter;

/**
 * Default {@see RateLimiter} backed by the Laravel cache. A fixed-window counter:
 * the TTL is set ONCE on the first hit (via `add()`) and the counter is bumped with
 * the store's atomic `increment()`, so concurrent hits cannot under-count and the
 * window does not slide on every request. Good enough for verification throttling and
 * the IRSF per-prefix circuit breaker; swap in a Redis sliding-window impl if needed.
 */
final class CacheRateLimiter implements RateLimiter
{
    public function __construct(private readonly Cache $cache) {}

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds): int
    {
        $counterKey = $this->counterKey($key);

        // Set the value + TTL only on the FIRST hit of the window (atomic add).
        $this->cache->add($counterKey, 0, $decaySeconds);

        return (int) $this->cache->increment($counterKey);
    }

    public function clear(string $key): void
    {
        $this->cache->forget($this->counterKey($key));
    }

    public function availableIn(string $key): int
    {
        // Without TTL introspection we cannot know the exact remaining seconds; report 0
        // when the window is empty. Provide a precise implementation if you need backoff UX.
        return $this->attempts($key) > 0 ? 1 : 0;
    }

    private function attempts(string $key): int
    {
        $value = $this->cache->get($this->counterKey($key), 0);

        return is_int($value) ? $value : 0;
    }

    private function counterKey(string $key): string
    {
        return 'rebel-rl:'.$key;
    }
}
