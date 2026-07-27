## Code Exploration Policy

Always use localmunch-MCP tools for code navigation. Never fall back to Read, Grep, Glob, or Bash for code exploration.
**Exception:** Use `Read` when you need to edit a file — the agent harness requires a `Read` before `Edit`/`Write` will succeed. Use localmunch tools to *find and understand* code, then `Read` only the specific file you're about to modify.

localmunch is local and zero-network (source: `~/Dev/AI/localmunch`), with the same tool names as the retired jcodemunch core. If the server is not configured, fall back to Read/Grep/Glob as normal.

**Start any session:**
1. `resolve_repo { "path": "." }` — confirm the project is indexed. If not: `index_folder { "path": "." }`

**Finding code:**
- symbol by name → `search_symbols` (add `kind=`, `file_pattern=`, `fuzzy=` to narrow)
- string, comment, config value → `search_text` (supports regex, `context_lines`)

**Reading code:**
- before opening any file → `get_file_outline` first
- one or more symbols → `get_symbol_source` (single ID → flat object; array → batch)
- symbol + its file's imports → `get_context_bundle`
- specific line range only → `get_file_content` (last resort)

**Repo structure:**
- `get_repo_outline` → dirs, languages, symbol counts
- `get_file_tree` → file layout, filter with `path_prefix`

**Relationships & impact:**
- where is this name used → `find_references` (word-boundary textual matching annotated with enclosing symbol — not semantic resolution; expect definition sites and same-named identifiers in results)
- is this identifier used anywhere → `check_references`
- blast radius / impact estimate → `find_references` on the symbol name, then `get_file_outline` on the hit files; treat as an approximation

**Interpreting search results:**
- If `search_symbols` returns `negative_evidence` with `verdict: "no_implementation_found"`:
  - Do NOT re-search with different terms hoping to find it
  - Do NOT assume a related file (e.g. auth middleware) implements the missing feature (e.g. CSRF)
  - DO report: "No existing implementation found for X. This would need to be created."

**After editing files:**
- Call `register_edit` with the edited repo-relative paths to force reindexing (the mtime check can miss same-size edits)
- For bulk edits, pass all paths in one `register_edit` call

<!-- BEGIN bridge-api-guide -->
## Deploying with The Bridge (bridge-api-guide MCP)

This project is deployed via **The Bridge**. A `bridge-api-guide` MCP server is
available (registered globally or in this repo) with tools that call The
Bridge deployment API directly — `list_branches`, `list_apps`, `deploy_app`,
`get_deployment`, `get_deployment_log`.

**Before any deploy or deployment-status task:**

1. Read the `bridge://api/overview` resource (base URL, bearer-token auth, error
   model) if you need the contract details.
2. Read the `bridge://api/actions/<slug>` resource for background on a
   specific action — slugs: `list-branches`, `list-apps`, `deploy-app`,
   `get-deployment`, `get-deployment-log`.
3. For multi-step jobs, invoke a prompt instead of improvising:
   - `deploy_and_watch(app_id)` — deploy and tail to completion
   - `find_and_deploy_branch(repo_url)` — resolve the app from a repo URL, deploy, watch
   - `check_deploy_status(deployment_id)` — report current status + log
4. Use the `list_apps` / `deploy_app` / `get_deployment` / `get_deployment_log`
   tools directly — no need to hand-roll HTTP calls. **Never assume success
   from `deploy_app`'s immediate response** — poll `get_deployment` /
   `get_deployment_log` until the deployment is terminal.

Example user prompts that should route through this server:
- "Deploy app 3 and watch it to completion."
- "Deploy the app for https://github.com/acme/widgets.git."
- "What's the status of deployment 42?"
<!-- END bridge-api-guide -->
