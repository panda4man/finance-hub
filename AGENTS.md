## Code Exploration Policy

Always use jCodemunch-MCP tools for code navigation. Never fall back to Read, Grep, Glob, or Bash for code exploration.
**Exception:** Use `Read` when you need to edit a file — the agent harness requires a `Read` before `Edit`/`Write` will succeed. Use jCodemunch tools to *find and understand* code, then `Read` only the specific file you're about to modify.

**Start any session:**
1. `resolve_repo { "path": "." }` — confirm the project is indexed. If not: `index_folder { "path": "." }`
2. `suggest_queries` — when the repo is unfamiliar

**Finding code:**
- symbol by name → `search_symbols` (add `kind=`, `language=`, `file_pattern=`, `decorator=` to narrow)
- decorator-aware queries → `search_symbols(decorator="X")` to find symbols with a specific decorator (e.g. `@property`, `@route`); combine with set-difference to find symbols *lacking* a decorator (e.g. "which endpoints lack CSRF protection?")
- string, comment, config value → `search_text` (supports regex, `context_lines`)
- database columns (dbt/SQLMesh) → `search_columns`

**Reading code:**
- before opening any file → `get_file_outline` first
- one or more symbols → `get_symbol_source` (single ID → flat object; array → batch)
- symbol + its imports → `get_context_bundle`
- specific line range only → `get_file_content` (last resort)

**Repo structure:**
- `get_repo_outline` → dirs, languages, symbol counts
- `get_file_tree` → file layout, filter with `path_prefix`

**Relationships & impact:**
- what imports this file → `find_importers`
- where is this name used → `find_references`
- is this identifier used anywhere → `check_references`
- file dependency graph → `get_dependency_graph`
- what breaks if I change X → `get_blast_radius`
- what symbols actually changed since last commit → `get_changed_symbols`
- find unreachable/dead code → `find_dead_code`
- class hierarchy → `get_class_hierarchy`

## Session-Aware Routing

**Opening move for any task:**
1. `plan_turn { "repo": "...", "query": "your task description", "model": "<your-model-id>" }` — get confidence + recommended files; the `model` parameter narrows the exposed tool list to match your capabilities at zero extra requests.
2. Obey the confidence level:
   - `high` → go directly to recommended symbols, max 2 supplementary reads
   - `medium` → explore recommended files, max 5 supplementary reads
   - `low` → the feature likely doesn't exist. Report the gap to the user. Do NOT search further hoping to find it.
3. **One-call shortcut for a concrete task** — `assemble_task_context { "repo": "...", "task": "..." }` returns a single token-budgeted, source-attributed context capsule. It auto-classifies the task (explore / debug / refactor / extend / audit / review), auto-extracts anchor symbols, and runs the intent-appropriate sequence of the tools below end-to-end — so you get the whole context in one request instead of chaining the primitives by hand. Prefer it over a manual chain when the task is well-defined; fall back to step 1's routing when you need to decide *whether* the feature exists first.

**Interpreting search results:**
- If `search_symbols` returns `negative_evidence` with `verdict: "no_implementation_found"`:
  - Do NOT re-search with different terms hoping to find it
  - Do NOT assume a related file (e.g. auth middleware) implements the missing feature (e.g. CSRF)
  - DO report: "No existing implementation found for X. This would need to be created."
  - DO check `related_existing` files — they show what's nearby, not what exists
- If `verdict: "low_confidence_matches"`: examine the matches critically before assuming they implement the feature

**After editing files:**
- If PostToolUse hooks are installed (Claude Code only), edited files are auto-reindexed
- Otherwise, call `register_edit` with edited file paths to invalidate caches and keep the index fresh
- For bulk edits (5+ files), always use `register_edit` with all paths to batch-invalidate

**Token efficiency:**
- If `_meta` contains `budget_warning`: stop exploring and work with what you have
- If `auto_compacted: true` appears: results were automatically compressed due to turn budget
- Use `get_session_context` to check what you've already read — avoid re-reading the same files

## Model-Driven Tool Tiering

Your jcodemunch-mcp server narrows the exposed tool list based on the model you are running as. To avoid wasting requests on primitives when a composite would do, always include `model="<your-model-id>"` in your opening `plan_turn` call.

Replace `<your-model-id>` with your active model:
- Claude Opus variants → `claude-opus-4-7` (or any `claude-opus-*`)
- Claude Sonnet variants → `claude-sonnet-4-6`
- Claude Haiku variants → `claude-haiku-4-5`
- GPT-4o / GPT-5 / o1 / Llama → use the model id as printed by your runner

The `model=` parameter rides on the existing `plan_turn` call — it does **not** add a separate tool invocation. If `plan_turn` is not appropriate for a non-code task, call `announce_model(model="...")` once instead.

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
