# finance-hub

Backend service: nightly SimpleFin transaction sync into a categorized, provider-agnostic Postgres schema.

## Setup

Copy `.env.example` to `.env` and fill in `DATABASE_URL`, `ENCRYPTION_KEY` (`openssl rand -base64 32`), and `INTERNAL_API_TOKEN` (`openssl rand -hex 32`).

```
docker compose up -d postgres
npm run db:migrate
npm run start:dev
```

Then connect a bank — see below.

## Connecting a bank

Sync is powered by [SimpleFin](https://www.simplefin.org/), not a bank-specific SDK — no client secret, no OAuth redirect flow. All you need is a **setup token**:

1. Get a setup token from your SimpleFin bridge (e.g. [bridge.simplefin.org](https://bridge.simplefin.org), where you link your actual bank logins). One bridge account/token can cover several institutions at once.
2. Run `npm run configure` — it prints a `/connections?token=...` URL.
3. Open that URL and paste the setup token in. This claims it for a permanent credential and pulls in every account it covers.

Re-auth works the same way: get a fresh setup token from the bridge and paste it in again. Accounts that already exist (matched by their SimpleFin id) get the stored credential refreshed in place instead of creating duplicates.

Credentials are stored encrypted (`ENCRYPTION_KEY`), one row per claimed setup token, in the `connections` table — see `src/simplefin/` for the protocol client and `src/connections/` for the claim/refresh flow.

## Internal API

Two endpoint groups, both guarded by `InternalTokenGuard` — every request needs `Authorization: Bearer <INTERNAL_API_TOKEN>` (or `?token=<INTERNAL_API_TOKEN>`):

- `POST /internal/sync/run?connectionId=<uuid>` — sync one connection, or all active connections if `connectionId` omitted
- `GET /internal/sync/status` — latest sync run per connection
- `GET /internal/transactions?limit&offset&sortBy&order` — paginated/sortable transactions (`sortBy`: `date|amount|name|merchantName`, `order`: `asc|desc`, `limit` max 200)

The CLI and MCP server below are both thin wrappers over this API — same auth, same routes.

## CLI

```
npm run cli -- sync run [--connection-id <uuid>] [--json]
npm run cli -- sync status [--json]
npm run cli -- transactions list [--limit N] [--offset N] [--sort-by date|amount|name|merchantName] [--order asc|desc] [--json]
npm run cli -- --help
```

Human-readable output by default (table for transactions, one-line summaries for sync); pass `--json` for raw API JSON.

**Config (env vars):**

| Var | Default | Purpose |
|---|---|---|
| `FINANCE_HUB_API_URL` | — | full base URL override, e.g. `http://prod-host:3000` |
| `PORT` | `3000` | used to build the base URL if `FINANCE_HUB_API_URL` unset |
| `PUBLIC_HOST` | `localhost` | used to build the base URL if `FINANCE_HUB_API_URL` unset |
| `INTERNAL_API_TOKEN` | — | required; sent as the bearer token |

Loaded from `.env` in the repo root automatically (via `src/common/env.ts`).

## MCP server

Stdio MCP server wrapping the same API, for AI agents. Start with `npm run mcp`. Registered for Claude Code via the repo-root `.mcp.json`:

```json
{
  "mcpServers": {
    "finance-hub": { "command": "npm", "args": ["run", "mcp", "--silent"] }
  }
}
```

**Tools:**

| Tool | Input | Maps to |
|---|---|---|
| `sync_run` | `{ connectionId?: string (uuid) }` | `POST /internal/sync/run` |
| `sync_status` | `{}` | `GET /internal/sync/status` |
| `list_transactions` | `{ limit?, offset?, sortBy?: date\|amount\|name\|merchantName, order?: asc\|desc }` | `GET /internal/transactions` |

Each tool returns the API's JSON response as text content; failures come back as `isError: true` with the error message (never a raw stack trace on stdout — stdio transport requires stdout to stay pure JSON-RPC).

Uses the same env vars as the CLI (`FINANCE_HUB_API_URL`/`PORT`/`PUBLIC_HOST`, `INTERNAL_API_TOKEN`), loaded from `.env` in the repo root.
