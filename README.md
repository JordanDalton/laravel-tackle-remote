# Laravel Tackle Remote

**A browser UI for [Laravel Tackle](https://github.com/JordanDalton/laravel-tackle) — drive your in-app AI coding agent from any device, including your phone.**

> 📚 **Full documentation: [tackle.jordandalton.com](https://tackle.jordandalton.com/integrations/remote)**

```bash
php artisan tackle:remote --host=0.0.0.0
```

Run it, scan the QR code printed in your terminal, and your phone is now a
remote control for the agent running inside your Laravel app: send it tasks
— including photos straight from your camera — watch it work tool-by-tool,
and answer its approval prompts from a bottom sheet — *"Tackle wants to run
`php artisan migrate` — Deny / Allow once / Always allow."*

It is the same harness as `ai:code` — same agent, same tools, same safety
layer (protected paths, allowlists, budget, [hooks](https://github.com/JordanDalton/laravel-tackle#hooks),
[subagents](https://github.com/JordanDalton/laravel-tackle#subagents)), same
persistent sessions. Only the terminal is replaced by a web page.

## Installation

If Tackle v1.23+ is already installed, one command does it:

```bash
php artisan tackle:install remote
```

Or via Composer directly:

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

The command prints a pairing URL (and its QR code). **Pairing links are
single-use**: the first device to open one is paired and receives a signed
session cookie; the link then expires and the terminal prints a fresh QR for
the next device. Sessions — and everything they're signed with — die with
the process.

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

Auth is handled by `AccessGuard` (single-use pairing codes, HMAC-signed
session cookies, failure lockout) — see [Security](#security).

**Slash commands and @-mentions**: typing `/` in the composer offers the
same commands the `ai:code` terminal has — `/clear`, `/compact`, `/help`,
plus your project's `.tackle/commands/*.md` templates, expanded server-side
with identical `$ARGUMENTS` semantics. Typing `@` offers workspace files
from a git-aware index (`git ls-files`, so `.env`, `vendor/`, and everything
gitignored never appear), refreshed after each turn; an `@`-mentioned image
on the server attaches as vision input, exactly as in the terminal.

**Photos**: the 📷 button attaches images from the camera or library. They
are downscaled on-device to ~1600px JPEG (an iPhone HEIC becomes ~300KB and
Safari converts it for free), validated server-side (type whitelist, 5 MB
cap), and flow to the model through `laravel/ai` image attachments — so the
agent can read a whiteboard, an error screenshot, or a UI sketch. Like
`ai:code`, attachments belong to the turn they're sent with and are pruned
on clear; they are not persisted across session resumes.

Because state is files, the UI survives page reloads, multiple devices can
watch the same session, and there is no websocket infrastructure to run.

## Security

This endpoint edits files and runs commands on the machine it serves — treat
it with SSH-grade caution. The model:

- **Single-use pairing.** The QR URL carries a 128-bit pairing code that is
  consumed by the first visit — a copied or replayed link gets nothing. Only
  a hash of the code ever touches disk. Each claim triggers a fresh code for
  the next device.
- **Signed sessions.** Paired devices hold an HMAC-signed, `HttpOnly`,
  `SameSite=Strict` cookie with an expiry (12h default, sliding renewal on
  use). The signing secret lives only in process memory and the server
  child's environment — never on disk — so every session dies with the
  process.
- **Failure lockout.** Repeated bad attempts from an address get a
  temporary lockout. Valid cookies are exempt, so an attacker spamming
  codes cannot lock out your paired phone.
- **Timeouts deny.** An unanswered approval question is denied, never
  approved.
- **Local by default.** Binds `127.0.0.1`; exposing to the LAN is an
  explicit `--host=0.0.0.0` choice.
- All of core Tackle's guarantees still apply underneath: `PathGuard`,
  artisan/shell allowlists, budget enforcement, hooks.

## Running on a cloud server

The supported way to reach a cloud-hosted Tackle Remote is to **not expose
it at all** — put the server and your phone on the same private network:

1. Install [Tailscale](https://tailscale.com) (or any WireGuard mesh) on the
   server and your phone.
2. Run `php artisan tackle:remote --host=<tailnet-ip>` (find it with
   `tailscale ip -4`).
3. Scan the QR. The URL is a `100.x` address only your authenticated
   devices can route to; the public internet never sees a port.

Traffic is end-to-end encrypted and device identity is enforced by the mesh
— the pairing/cookie layer becomes defense in depth instead of the only
door.

If you must serve over the public internet, do not point `php -S` at it:
terminate TLS at a real proxy (Caddy, nginx) and put an identity layer in
front (Cloudflare Access, VPN, at minimum proxy auth). Plain HTTP on a
public interface means cookies and pairing codes travel in cleartext —
never do that.

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
