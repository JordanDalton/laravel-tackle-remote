<?php

use TackleRemote\Support\AccessGuard;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/tackle-remote-guard-'.uniqid();
    mkdir($this->dir, 0755, true);
    $this->guard = new AccessGuard($this->dir, 'test-secret', 100);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

it('claims a pairing code exactly once', function () {
    $code = $this->guard->issuePairingCode();

    expect($this->guard->hasUnclaimedCode())->toBeTrue()
        ->and($this->guard->claimPairingCode($code))->toBeTrue()
        ->and($this->guard->claimPairingCode($code))->toBeFalse()
        ->and($this->guard->hasUnclaimedCode())->toBeFalse();
});

it('rejects a wrong code without consuming the real one', function () {
    $code = $this->guard->issuePairingCode();

    expect($this->guard->claimPairingCode('not-the-code'))->toBeFalse()
        ->and($this->guard->hasUnclaimedCode())->toBeTrue()
        ->and($this->guard->claimPairingCode($code))->toBeTrue();
});

it('stores only a hash of the pairing code on disk', function () {
    $code = $this->guard->issuePairingCode();

    expect(file_get_contents($this->dir.'/pairing.json'))->not->toContain($code);
});

it('issuing a new code invalidates the previous one', function () {
    $old = $this->guard->issuePairingCode();
    $new = $this->guard->issuePairingCode();

    expect($this->guard->claimPairingCode($old))->toBeFalse()
        ->and($this->guard->claimPairingCode($new))->toBeTrue();
});

it('round-trips a fresh session cookie as valid', function () {
    $cookie = $this->guard->mintCookie(now: 1000);

    expect($this->guard->checkCookie($cookie, now: 1000))->toBe('valid');
});

it('flags a cookie past half-life for renewal, then expires it', function () {
    $cookie = $this->guard->mintCookie(now: 1000); // expires at 1100

    expect($this->guard->checkCookie($cookie, now: 1049))->toBe('valid')
        ->and($this->guard->checkCookie($cookie, now: 1051))->toBe('renew')
        ->and($this->guard->checkCookie($cookie, now: 1100))->toBe('invalid');
});

it('rejects tampered and malformed cookies', function () {
    $cookie = $this->guard->mintCookie();
    [$payload, $signature] = explode('.', $cookie);

    $forged = base64_encode(json_encode(['iat' => time(), 'exp' => time() + 999999]));

    expect($this->guard->checkCookie($forged.'.'.$signature))->toBe('invalid')
        ->and($this->guard->checkCookie($payload.'.wrong'))->toBe('invalid')
        ->and($this->guard->checkCookie('garbage'))->toBe('invalid')
        ->and($this->guard->checkCookie(null))->toBe('invalid')
        ->and($this->guard->checkCookie(['array']))->toBe('invalid');
});

it('rejects cookies signed with a different secret', function () {
    $other = new AccessGuard($this->dir, 'different-secret', 100);

    expect($this->guard->checkCookie($other->mintCookie()))->toBe('invalid');
});

it('locks out an address after repeated failures within the window', function () {
    foreach (range(1, 9) as $i) {
        $this->guard->registerFailure('1.2.3.4', now: 1000 + $i);
    }

    expect($this->guard->lockedOut('1.2.3.4', now: 1010))->toBeFalse();

    $this->guard->registerFailure('1.2.3.4', now: 1010);

    expect($this->guard->lockedOut('1.2.3.4', now: 1010))->toBeTrue()
        ->and($this->guard->lockedOut('5.6.7.8', now: 1010))->toBeFalse()
        ->and($this->guard->lockedOut('1.2.3.4', now: 1200))->toBeFalse();
});

it('sees a claim made by another process', function () {
    $this->guard->issuePairingCode();

    // Prime this process's stat cache with a successful stat.
    expect($this->guard->hasUnclaimedCode())->toBeTrue();

    // Another process (the HTTP router) consumes the code.
    exec('rm '.escapeshellarg($this->dir.'/pairing.json'));

    expect($this->guard->hasUnclaimedCode())->toBeFalse();
});
