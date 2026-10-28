<?php

declare(strict_types=1);

namespace GroceryRestable\Support;

use GroceryRestable\Exception\ApiException;

/**
 * Minimal HMAC JWT (HS256 / HS384 / HS512) encoder and verifier.
 */
final class Jwt
{
    private const ALGORITHMS = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];

    /**
     * @param array<string, mixed> $claims
     */
    public static function encode(array $claims, string $secret, string $algorithm = 'HS256'): string
    {
        if (! isset(self::ALGORITHMS[$algorithm])) {
            throw new \InvalidArgumentException('Unsupported JWT algorithm ' . $algorithm);
        }

        $header = self::b64(json_encode(['alg' => $algorithm, 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = self::b64(hash_hmac(self::ALGORITHMS[$algorithm], $header . '.' . $payload, $secret, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * Verify the signature and the time based claims, return the claims.
     *
     * @return array<string, mixed>
     */
    public static function decode(
        string $token,
        string $secret,
        int $leeway = 60,
        ?string $audience = null,
        ?string $issuer = null,
        ?string $algorithm = null,
        bool $requireExpiry = false,
    ): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw ApiException::unauthorized('Malformed token.');
        }

        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::unb64($h), true);
        $claims = json_decode((string) self::unb64($p), true);

        if (! is_array($header) || ! is_array($claims)) {
            throw ApiException::unauthorized('Malformed token.');
        }

        $alg = $header['alg'] ?? '';
        // Only HMAC algorithms are accepted; "none" and asymmetric algorithms are rejected.
        if (! is_string($alg) || ! isset(self::ALGORITHMS[$alg]) || ($algorithm !== null && $alg !== $algorithm)) {
            throw ApiException::unauthorized('Unsupported token algorithm.');
        }

        $expected = hash_hmac(self::ALGORITHMS[$alg], $h . '.' . $p, $secret, true);
        if (! hash_equals($expected, (string) self::unb64($s))) {
            throw ApiException::unauthorized('Invalid token signature.');
        }

        $now = time();
        foreach (['exp', 'nbf', 'iat'] as $timeClaim) {
            if (isset($claims[$timeClaim]) && ! is_int($claims[$timeClaim]) && ! is_float($claims[$timeClaim])) {
                throw ApiException::unauthorized('Invalid "' . $timeClaim . '" claim.');
            }
        }
        if ($requireExpiry && ! isset($claims['exp'])) {
            throw ApiException::unauthorized('The token has no expiry ("exp" claim).');
        }
        if (isset($claims['exp']) && $now - $leeway >= (int) $claims['exp']) {
            throw ApiException::unauthorized('Token has expired.');
        }
        if (isset($claims['nbf']) && $now + $leeway < (int) $claims['nbf']) {
            throw ApiException::unauthorized('Token is not valid yet.');
        }
        if ($audience !== null && $audience !== '') {
            $aud = (array) ($claims['aud'] ?? []);
            if (! in_array($audience, $aud, true)) {
                throw ApiException::unauthorized('Invalid token audience.');
            }
        }
        if ($issuer !== null && $issuer !== '' && ($claims['iss'] ?? null) !== $issuer) {
            throw ApiException::unauthorized('Invalid token issuer.');
        }

        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
