# UPGRADING — Mail MCP Server

Developer/agent-facing. Read this **before** touching `libraries/`,
`bin/`, or any network I/O. Operator setup and tool docs live in
`docs/SETUP.md` / `docs/TOOLS.md`; user-facing changes in `CHANGELOG.md`.

## 1. The transport split (September 2026) changed the rules

Three independent libraries, vendored byte-identical from canonical
upstream **master** (pull first, then copy — never from another
consumer's tree):

| Vendored dir | Source | What it is |
|---|---|---|
| `libraries/EnchiladaMCP/` | `Enchilada/Extras` MCP/ | Protocol core. No I/O, no loop, no framework deps. |
| `libraries/Enchilada/Tortilla/` | `Enchilada/Tortilla` src/ | Wire transports, `EventLoop` port, `ComalEventLoop`, `HttpClient`, `RequestEra`, `Oauth3LOClient`. |
| `libraries/EnchiladaHTTP/` | Extras HTTP/ | Blocking engine (legacy global class). **Frozen.** |
| `libraries/EnchiladaMultiHTTP/` | Extras HTTP/ | curl_multi engine (legacy global class). **Frozen.** |
| `libraries/Enchilada/Comal/` | `Enchilada/Comal` | Reactor (kqueue/ev). Presence opts stdio into reactor mode. |
| `libraries/EnchiladaOAuth/` | Extras OAuth/ | Pure `Oauth3LO` core (no HTTP types) + legacy wrapper + non-blocking `OAuthCallbackServer`. |

## 2. Non-negotiables

1. **Eponymous vendoring, no guards.** Legacy global classes live in
   `libraries/<Class>/<Class>.class.php`. Never add
   `class_exists`/`require_once` guards: the framework autoloader is
   golden; a miss means wrong placement or namespace — fix that.
2. **Only `bin/mail-mcp` knows both sides.** Transports take primitives
   (`handler`, `progress` callables, version lists), never `McpServer`.
3. **The event loop is opt-in and app-owned:**
   `ComalEventLoop::create()` → `$transport->setLoop($loop)` → reactor
   I/O; `null` → blocking mode. Both are supported configurations.
4. **Blocking mode must still breathe.** `ping` is gone in MCP revision
   2026-07-28, so `notifications/progress` is the *only* in-call
   liveness a modern host gets. Any client with a bounded-poll regime
   must invoke its injected progress callable on every poll slice.
5. **No blocking network I/O inside tools.**
   - HTTP → `Tortilla\HttpClient` (loop-aware wait; mirrors
     `EnchiladaHTTP::call()`).
   - OAuth token HTTP → `Tortilla\Oauth3LOClient`.
   - Raw-socket clients → the `setTransport(?EventLoop, ?progress)`
     pattern (reference implementation here: `SocketImapClient`): a
     permanently non-blocking socket, a buffered pump, fiber-parked
     `onReadable` waits under the reactor, bounded 100 ms poll slices
     with per-slice progress without it.
   - `EventLoop` has **no writability watcher**: `waitWritable` must
     probe (zero-timeout `stream_select`) between parked slices, and an
     async connect still in flight reads `ENOTCONN` from
     `stream_socket_get_name` — re-probe, never treat it as failure.
     (mail-mcp#27 review.)
   - `EnchiladaHTTP`/`EnchiladaMultiHTTP` are frozen: no MCP hooks, no
     behavior changes.
6. **`bin/build-phar.php` is the release artifact** — phar smoke after
   every vendor change (init/version/tools/ping/stderr/EOF).

## 3. This server's wiring (`bin/mail-mcp` is the composition root)

- `$loop = ComalEventLoop::create()` — reactor where kqueue/ev exists.
- `ConnectionTools::setTransport($transport)` — OAuth callback listener
  is registered on the transport via `addStream`.
- `ConnectionTools::setHttpTransport($loop, $server->tick(...))` —
  OAuth token exchange/refresh (`Tortilla\Oauth3LOClient`).
- `InstanceManager::setImapTransport($loop, $server->tick(...))` —
  every `SocketImapClient` (current cached + future) gets the pair;
  IMAP I/O is event-driven (permanently non-blocking socket + buffered
  pump). DNS resolution and the implicit-TLS handshake are the
  documented residual blocks (bounded by the connect timeout).
- `SocketSmtpClient` is still the old blocking shape — apply the
  `SocketImapClient` pattern when it's next touched.
- Pending upstream: once `Enchilada/Extras#49` (non-blocking
  `OAuthCallbackServer`) merges, re-vendor `libraries/EnchiladaOAuth/`
  from that master with a byte-identity check.

## 4. Regression gates (before every commit)

- `phpunit` — all tests green.
- Liveness suite:
  `php ~/Documents/Projects/engineering-docs/enchilada-extras/mcp-liveness-suite/transport-liveness.php --lib=libraries`
  (Comal present → reactor-mode assertions apply) — 9/9.
- Phar/mail smoke harness; expect version read from `system/app.conf.php`
  (smoke pins must not be static strings).

## 5. Canonical references

- `engineering-docs/enchilada-extras/PLAN-TRANSPORT-SPLIT.md`
- `engineering-docs/enchilada-extras/DESIGN-RATIONALE-TRANSPORT-SPLIT.md`
- `engineering-docs/enchilada-extras/mcp-liveness-suite/README.md`
- `Enchilada/Extras` README (vendoring rules), `Enchilada/Tortilla` README
