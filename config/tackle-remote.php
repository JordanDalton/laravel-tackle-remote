<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bind Address / Port
    |--------------------------------------------------------------------------
    |
    | Where `php artisan tackle:remote` serves the UI. The default binds to
    | localhost only — pass --host=0.0.0.0 (or set this) explicitly to expose
    | the UI to your LAN so a phone can reach it. Every request still requires
    | the per-run access token, but binding beyond localhost is a deliberate
    | choice you should make knowingly.
    |
    */
    'host' => env('TACKLE_REMOTE_HOST', '127.0.0.1'),
    'port' => (int) env('TACKLE_REMOTE_PORT', 8787),

    /*
    |--------------------------------------------------------------------------
    | State Directory
    |--------------------------------------------------------------------------
    |
    | The artisan process (the agent) and the HTTP server (the UI) share state
    | through files under this directory: the event log the UI polls, the
    | inbox of user messages, and pending approval questions. One
    | subdirectory per session name.
    |
    */
    'storage_path' => env('TACKLE_REMOTE_STORAGE', storage_path('tackle-remote')),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a paired device's signed session cookie stays valid. Cookies
    | past half-life are transparently renewed on use, so an active device
    | never gets logged out — this bounds how long a *stolen* cookie works.
    | Everything is signed with a per-run secret, so every session also dies
    | when the tackle:remote process stops.
    |
    */
    'session_lifetime' => (int) env('TACKLE_REMOTE_SESSION_LIFETIME', 43200),

    /*
    |--------------------------------------------------------------------------
    | Approval Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | How long the agent waits for you to answer an approval prompt in the
    | browser before giving up. A timed-out confirmation is denied (never
    | approved), and the agent is told to continue accordingly. Generous by
    | default — the whole point is that you might be away from the terminal.
    |
    */
    'answer_timeout' => (int) env('TACKLE_REMOTE_ANSWER_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Poll Interval (milliseconds)
    |--------------------------------------------------------------------------
    |
    | How often the agent loop checks the inbox for new messages, and how
    | often the browser UI polls for new events. 400ms is indistinguishable
    | from streaming in a chat UI while keeping the server load trivial.
    |
    */
    'poll_interval_ms' => (int) env('TACKLE_REMOTE_POLL_MS', 400),

];
