<?php

declare(strict_types=1);

namespace GroceryRestable\Http;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Resource\Resource;
use GroceryRestable\Store\ConfigStore;

/**
 * Fixed window rate limiter for the REST API.
 *
 * Who is counted:
 *   - anonymous callers (role "anon", even with the public anon key) by client IP,
 *   - API keys by key id, JWT callers by their "sub" claim (IP when missing).
 * Limits come from the settings (rate_limit_*), or from Resource::setRateLimit() for a resource,
 * which then has its own counter. service_role is exempt while rate_limit_exempt_service is on.
 *
 * Counters live in APCu when available, otherwise in the SQLite configuration store.
 */
final class RateLimiter
{
    public function __construct(
        private readonly ConfigStore $store,
        private readonly bool $useApcu = false,
    ) {
    }

    public static function create(ConfigStore $store): self
    {
        return new self($store, function_exists('apcu_inc') && function_exists('apcu_enabled') && apcu_enabled());
    }

    /**
     * Count one request. Returns null when the request is not limited, otherwise
     * ['limit' => int, 'remaining' => int, 'reset' => seconds, 'allowed' => bool].
     *
     * @return array{limit: int, remaining: int, reset: int, allowed: bool}|null
     */
    public function hit(AuthContext $auth, string $clientIp, ?Resource $resource = null): ?array
    {
        $settings = $this->store->settings();
        if (! ($settings['rate_limit_enabled'] ?? true)) {
            return null;
        }
        if ($auth->isService() && ($settings['rate_limit_exempt_service'] ?? true)) {
            return null;
        }

        $own = $resource?->getRateLimit();
        if ($own !== null) {
            [$limit, $window, $scope] = [$own['requests'], $own['window'], 'r:' . $resource->getName()];
        } else {
            // Anonymous callers have their own (usually lower) limit; 0 means "same as everybody".
            $anonLimit = (int) ($settings['rate_limit_anon_requests'] ?? 0);
            $limit = $auth->isAnonymous() && $anonLimit > 0 ? $anonLimit : (int) ($settings['rate_limit_requests'] ?? 0);
            [$window, $scope] = [max(1, (int) ($settings['rate_limit_window'] ?? 60)), 'g'];
        }
        if ($limit <= 0) {
            return null;
        }

        $now = time();
        $start = $now - ($now % $window);
        $hits = $this->count($scope . '|' . $this->identity($auth, $clientIp), $window, $start);

        return [
            'limit' => $limit,
            'remaining' => max(0, $limit - $hits),
            'reset' => $start + $window - $now,
            'allowed' => $hits <= $limit,
        ];
    }

    private function identity(AuthContext $auth, string $clientIp): string
    {
        $ip = 'ip:' . ($clientIp !== '' ? $clientIp : 'unknown');
        if ($auth->isAnonymous()) {
            return $ip; // the anon key is public: limit each client, not the key
        }
        if ($auth->apiKeyId !== null) {
            return 'key:' . $auth->apiKeyId;
        }
        $sub = $auth->userId();

        return is_scalar($sub) && (string) $sub !== '' ? 'sub:' . hash('sha256', (string) $sub) : $ip;
    }

    private function count(string $key, int $window, int $start): int
    {
        if ($this->useApcu) {
            $apcuKey = 'grocery_restable_rl:' . hash('sha256', $key) . ':' . $start;
            apcu_add($apcuKey, 0, $window + 1);
            $hits = apcu_inc($apcuKey);
            if (is_int($hits)) {
                return $hits;
            }
        }

        return $this->store->hitRateLimit(hash('sha256', $key), $start);
    }
}
