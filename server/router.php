<?php

/**
 * The HTTP side of tackle:remote, served by PHP's built-in server:
 *
 *   php -S <host>:<port> router.php
 *
 * Deliberately framework-free: it loads only the Composer autoloader (for
 * RemoteState/AccessGuard) and speaks to the agent process purely through
 * the shared state directory. Requests are file reads and writes measured
 * in microseconds, which is what makes single-threaded php -S sufficient.
 *
 * Auth: the QR URL carries a single-use pairing code (?pair=). The first
 * visit consumes it in exchange for a signed HttpOnly session cookie;
 * everything after authenticates by cookie alone. Repeated failures from
 * one address trip a temporary lockout.
 */

use TackleRemote\Support\AccessGuard;
use TackleRemote\Support\RemoteState;

require getenv('TACKLE_REMOTE_AUTOLOAD');

$state = new RemoteState((string) getenv('TACKLE_REMOTE_DIR'));
$guard = new AccessGuard(
    $state->dir(),
    (string) getenv('TACKLE_REMOTE_SECRET'),
    (int) (getenv('TACKLE_REMOTE_LIFETIME') ?: 43200),
);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

$secureContext = ! empty($_SERVER['HTTPS'])
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

$setSessionCookie = static function () use ($guard, $secureContext): void {
    setcookie(AccessGuard::COOKIE_NAME, $guard->mintCookie(), [
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => $secureContext,
        'path' => '/',
    ]);
};

$cookieStatus = $guard->checkCookie($_COOKIE[AccessGuard::COOKIE_NAME] ?? null);
$authenticated = $cookieStatus !== 'invalid';

if ($authenticated && $cookieStatus === 'renew') {
    $setSessionCookie();
}

// The lockout gates only unauthenticated attempts: a validly signed cookie
// cannot be brute-forced, so honoring it during a lockout is safe — and it
// means an attacker spamming bad codes cannot lock out the paired device.
if (! $authenticated) {
    if ($guard->lockedOut($ip)) {
        http_response_code(429);
        header('Content-Type: text/plain');
        echo "Too many failed attempts. Wait a minute and try again.\n";

        return;
    }

    if (isset($_GET['pair']) && is_string($_GET['pair']) && $guard->claimPairingCode($_GET['pair'])) {
        $authenticated = true;
        $setSessionCookie();
    }
}

if (! $authenticated) {
    $guard->registerFailure($ip);
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Not paired. Pairing links are single-use — get a fresh QR from the `php artisan tackle:remote` terminal.\n";

    return;
}

$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
};

$body = static function (): array {
    $decoded = json_decode((string) file_get_contents('php://input'), true);

    return is_array($decoded) ? $decoded : [];
};

match (true) {
    $path === '/' && $method === 'GET' => (static function () {
        header('Content-Type: text/html; charset=utf-8');
        readfile((string) getenv('TACKLE_REMOTE_SPA'));
    })(),

    $path === '/api/poll' && $method === 'GET' => (static function () use ($state, $json) {
        $after = max(0, (int) ($_GET['after'] ?? 0));

        $json([
            ...$state->eventsAfter($after),
            'state' => $state->state(),
            'question' => $state->pendingQuestion(),
        ]);
    })(),

    $path === '/api/message' && $method === 'POST' => (static function () use ($state, $json, $body) {
        $payload = $body();
        $text = trim((string) ($payload['text'] ?? ''));

        // Only ids that resolve to a stored attachment survive.
        $images = array_values(array_filter(
            array_map('strval', (array) ($payload['images'] ?? [])),
            fn (string $id) => $state->attachmentPath($id) !== null,
        ));

        if ($text === '' && $images === []) {
            $json(['error' => 'A message needs text or an image.'], 422);

            return;
        }

        if ($text === '') {
            $text = 'Please look at the attached image.';
        }

        $json(['id' => $state->pushMessage($text, $images)]);
    })(),

    $path === '/api/upload' && $method === 'POST' => (static function () use ($state, $json) {
        $file = $_FILES['image'] ?? null;

        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $json(['error' => 'No image received.'], 422);

            return;
        }

        try {
            $id = $state->storeAttachment(
                (string) $file['name'],
                (string) file_get_contents((string) $file['tmp_name']),
            );
        } catch (InvalidArgumentException $e) {
            $json(['error' => $e->getMessage()], 422);

            return;
        }

        $json(['id' => $id]);
    })(),

    $path === '/api/attachment' && $method === 'GET' => (static function () use ($state, $json) {
        $file = $state->attachmentPath((string) ($_GET['id'] ?? ''));

        if ($file === null) {
            $json(['error' => 'Not found.'], 404);

            return;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        header('Content-Type: '.(RemoteState::ATTACHMENT_TYPES[$extension] ?? 'application/octet-stream'));
        header('Cache-Control: private, max-age=31536000');
        readfile($file);
    })(),

    $path === '/api/clear' && $method === 'POST' => (static function () use ($state, $json) {
        $state->pushCommand('clear');
        $json(['ok' => true]);
    })(),

    $path === '/api/answer' && $method === 'POST' => (static function () use ($state, $json, $body) {
        $payload = $body();
        $id = (string) ($payload['id'] ?? '');
        $value = $payload['value'] ?? null;

        if ($id === '' || ($value === null || $value === '')) {
            $json(['error' => 'Both id and value are required.'], 422);

            return;
        }

        $state->answer($id, is_array($value) ? $value : (string) $value);
        $json(['ok' => true]);
    })(),

    default => $json(['error' => 'Not found.'], 404),
};
