<?php

/**
 * The HTTP side of tackle:remote, served by PHP's built-in server:
 *
 *   php -S <host>:<port> router.php
 *
 * Deliberately framework-free: it loads only the Composer autoloader (for
 * RemoteState) and speaks to the agent process purely through the shared
 * state directory. Requests are file reads and writes measured in
 * microseconds, which is what makes single-threaded php -S sufficient.
 *
 * Auth: every request must carry the per-run token — as ?t= on the first
 * visit (the QR code's URL), then as an X-Tackle-Token header or cookie.
 */

use TackleRemote\Support\RemoteState;

require getenv('TACKLE_REMOTE_AUTOLOAD');

$state = new RemoteState((string) getenv('TACKLE_REMOTE_DIR'));
$token = (string) getenv('TACKLE_REMOTE_TOKEN');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$provided = $_GET['t']
    ?? $_SERVER['HTTP_X_TACKLE_TOKEN']
    ?? $_COOKIE['tackle_remote_token']
    ?? '';

if (! is_string($provided) || ! hash_equals($token, $provided)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Invalid or missing token. Open the exact URL printed by `php artisan tackle:remote`.\n";

    return;
}

// A valid ?t= visit (the QR link) upgrades to a cookie so navigation
// and API calls don't need the query string.
if (isset($_GET['t'])) {
    setcookie('tackle_remote_token', $token, [
        'httponly' => false, // the SPA reads it to send as a header
        'samesite' => 'Strict',
        'path' => '/',
    ]);
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
        $text = trim((string) ($body()['text'] ?? ''));

        if ($text === '') {
            $json(['error' => 'Message text is required.'], 422);

            return;
        }

        $json(['id' => $state->pushMessage($text)]);
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
