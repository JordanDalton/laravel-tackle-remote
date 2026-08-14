# Laravel Tackle Remote

**A browser UI for [Laravel Tackle](https://github.com/JordanDalton/laravel-tackle) — drive your in-app AI coding agent from any device, including your phone.**

```bash
php artisan tackle:remote --host=0.0.0.0
```

Run it, scan the QR code printed in your terminal, and your phone is now a
remote control for the agent running inside your Laravel app: send it tasks,
watch it work tool-by-tool, and answer its approval prompts from a bottom
sheet — *"Tackle wants to run `php artisan migrate` — Deny / Allow once /
Always allow."*

It is the same harness as `ai:code` — same agent, same tools, same safety
layer (protected paths, allowlists, budget, [hooks](https://github.com/JordanDalton/laravel-tackle#hooks),
[subagents](https://github.com/JordanDalton/laravel-tackle#subagents)), same
persistent sessions. Only the terminal is replaced by a web page.

## Installation

```bash
composer require jordandalton/laravel-tackle-remote --dev
php artisan vendor:publish --tag=tackle-remote-config   # optional
```

Requires `jordandalton/laravel-tackle` ^1.22 and its configuration
(provider API key, etc.).

## Usage

```bash
# Localhost only (default) — for tunnels or same-machine browsers:
php artisan tackle:remote

# Expose to your LAN so your phone can reach it:
php artisan tackle:remote --host=0.0.0.0

# Options:
#   --port=8787       port to serve on
#   --session=web     session name; transcripts persist per name and resume
```

The command prints a URL containing a one-time access token (and its QR
code). Open it on any device on the network. The session — and the URL —
die with the process.

## How it works

`tackle:remote` is two processes sharing a state directory
(`storage/tackle-remote/<session>/`):

- The **artisan process** is the agent: it waits for messages in an inbox,
  runs each as an agent turn exactly like `ai:code` does, and appends every
  event (text, tool calls, budget) to an append-only log.
- A **framework-free HTTP server** (PHP's built-in server with a small
  router) serves a single-file mobile UI and three JSON endpoints:
  `GET /api/poll`, `POST /api/message`, `POST /api/answer`. Requests are
  file reads measured in microseconds; the UI polls at 400ms, which is
  indistinguishable from streaming for a chat interface.

Approval prompts flow through Tackle's `InteractionPolicy` contract: this
package binds a `RemoteInteraction` that publishes the question to the state
directory and waits for the browser's tap. "Always allow" writes through to
Tackle's `PermissionStore`, exactly like answering in the terminal.
Unanswered questions time out to a **denial** (never an approval) after
`answer_timeout` seconds (default 600).

Because state is files, the UI survives page reloads, multiple devices can
watch the same session, and there is no websocket infrastructure to run.

## Security

- Binds to `127.0.0.1` by default. Exposing to the LAN is an explicit
  `--host=0.0.0.0` choice.
- Every request requires the per-run random token (128-bit), carried by the
  QR URL and upgraded to a cookie. Constant-time comparison; no token, no
  response.
- Anyone with the link can drive the agent — treat the QR like a session
  cookie. The token dies with the process.
- All of core Tackle's guarantees still apply underneath: `PathGuard`,
  artisan/shell allowlists, budget enforcement, hooks.
- For access beyond your LAN, put it behind a tunnel you trust
  ([Expose](https://expose.dev), ngrok, Cloudflare Tunnel). Do not port-forward
  it raw to the internet.

## Roadmap

- Push notifications for self-healer approvals — review the diff and approve
  a fix PR from your phone.
- Session switcher and transcript browser in the UI.
- Hosted relay for zero-tunnel access from anywhere.

## Development

```bash
composer install
./vendor/bin/pest
./vendor/bin/pint
```

To develop against a local checkout of core Tackle:

```bash
composer config repositories.tackle path ../tackle
composer require "jordandalton/laravel-tackle:@dev"
```

## License

MIT
