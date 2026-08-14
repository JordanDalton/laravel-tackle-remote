<?php

namespace TackleRemote\Support;

/**
 * Authentication for tackle:remote, hardened for hostile networks:
 *
 *  - Pairing codes are single-use. The QR URL carries one; the first visit
 *    consumes it in exchange for a session cookie, and every later use is
 *    rejected. Only a hash of the code touches disk.
 *  - Session cookies are HMAC-signed claims (issued/expires) minted from a
 *    per-run secret that lives only in process env — never on disk. Cookies
 *    past half-life are reissued; expired or tampered ones are rejected.
 *  - Repeated auth failures from one address trip a temporary lockout.
 *
 * Framework-free on purpose: the HTTP router uses this without booting
 * Laravel. Time is injectable for tests.
 */
class AccessGuard
{
    public const COOKIE_NAME = 'tackle_remote_session';

    private const FAILURE_LIMIT = 10;

    private const FAILURE_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly string $dir,
        private readonly string $secret,
        private readonly int $lifetimeSeconds = 43200,
    ) {}

    /*
    |----------------------------------------------------------------------
    | Pairing codes (single-use, QR-carried)
    |----------------------------------------------------------------------
    */

    public function issuePairingCode(): string
    {
        $code = bin2hex(random_bytes(16));

        file_put_contents($this->dir.'/pairing.json', json_encode([
            'hash' => hash_hmac('sha256', $code, $this->secret),
            'issued_at' => time(),
        ]));

        return $code;
    }

    /**
     * Whether an issued code is still waiting to be claimed. The agent
     * process watches this to print a fresh QR once a device pairs. The
     * claim happens in another process, so PHP's per-process stat cache
     * must be cleared or a stale positive sticks forever.
     */
    public function hasUnclaimedCode(): bool
    {
        clearstatcache(true, $this->dir.'/pairing.json');

        return is_file($this->dir.'/pairing.json');
    }

    /**
     * Claim a pairing code. True exactly once per issued code: the claim
     * consumes it atomically (rename wins races), so a copied QR replayed
     * later gets nothing. A wrong code does not consume anything.
     */
    public function claimPairingCode(string $code): bool
    {
        $path = $this->dir.'/pairing.json';

        clearstatcache(true, $path);

        if (! is_file($path)) {
            return false;
        }

        $stored = json_decode((string) file_get_contents($path), true);
        $expected = is_array($stored) ? ($stored['hash'] ?? '') : '';

        if (! is_string($expected) || $expected === ''
            || ! hash_equals($expected, hash_hmac('sha256', $code, $this->secret))) {
            return false;
        }

        $claimed = $path.'.claimed-'.bin2hex(random_bytes(4));

        if (! @rename($path, $claimed)) {
            return false; // Lost the race to a concurrent claim.
        }

        @unlink($claimed);

        return true;
    }

    /*
    |----------------------------------------------------------------------
    | Session cookies (signed claims)
    |----------------------------------------------------------------------
    */

    public function mintCookie(?int $now = null): string
    {
        $now ??= time();

        $payload = $this->base64UrlEncode((string) json_encode([
            'iat' => $now,
            'exp' => $now + $this->lifetimeSeconds,
        ]));

        return $payload.'.'.hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * @return 'valid'|'renew'|'invalid' 'renew' means the cookie is good but
     *                                   past half-life — accept it and set a fresh one.
     */
    public function checkCookie(mixed $cookie, ?int $now = null): string
    {
        $now ??= time();

        if (! is_string($cookie) || substr_count($cookie, '.') !== 1) {
            return 'invalid';
        }

        [$payload, $signature] = explode('.', $cookie);

        if (! hash_equals(hash_hmac('sha256', $payload, $this->secret), $signature)) {
            return 'invalid';
        }

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        $issued = is_array($claims) && is_int($claims['iat'] ?? null) ? $claims['iat'] : null;
        $expires = is_array($claims) && is_int($claims['exp'] ?? null) ? $claims['exp'] : null;

        if ($issued === null || $expires === null || $now >= $expires) {
            return 'invalid';
        }

        return ($now - $issued) > (($expires - $issued) / 2) ? 'renew' : 'valid';
    }

    /*
    |----------------------------------------------------------------------
    | Failure lockout
    |----------------------------------------------------------------------
    */

    public function registerFailure(string $key, ?int $now = null): void
    {
        $now ??= time();
        $failures = $this->failures();

        $failures[$key] = [...$this->recent($failures[$key] ?? [], $now), $now];

        file_put_contents($this->dir.'/failures.json', json_encode($failures), LOCK_EX);
    }

    public function lockedOut(string $key, ?int $now = null): bool
    {
        $now ??= time();

        return count($this->recent($this->failures()[$key] ?? [], $now)) >= self::FAILURE_LIMIT;
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function failures(): array
    {
        $path = $this->dir.'/failures.json';

        if (! is_file($path)) {
            return [];
        }

        $failures = json_decode((string) file_get_contents($path), true);

        return is_array($failures) ? $failures : [];
    }

    /**
     * @param  array<int, int>  $timestamps
     * @return array<int, int>
     */
    private function recent(array $timestamps, int $now): array
    {
        return array_values(array_filter(
            $timestamps,
            fn ($at) => is_int($at) && ($now - $at) < self::FAILURE_WINDOW_SECONDS,
        ));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
