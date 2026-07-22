import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

const execFileP = promisify(execFile);

const CONTAINER = process.env.FINANCE_HUB_CONTAINER ?? 'finance-hub-laravel-app';

function errorMessage(err) {
  if (err instanceof Error) {
    return err.message;
  }
  return String(err);
}

/**
 * Run `docker exec <container> php artisan <args> --no-interaction` and
 * parse the last non-blank line of stdout as JSON. Artisan commands used
 * here are expected to be invoked with --json and print exactly one JSON
 * line as their final output.
 */
async function artisan(args, { timeoutMs = 60_000 } = {}) {
  const argv = ['exec', CONTAINER, 'php', 'artisan', ...args, '--no-interaction'];
  const { stdout } = await execFileP('docker', argv, { timeout: timeoutMs, maxBuffer: 16 * 1024 * 1024 });
  const line = stdout.trim().split('\n').filter(Boolean).pop() ?? '';
  return JSON.parse(line);
}

/** Build a `--flag=value` argv entry, or [] when value is nullish. */
function opt(flag, value) {
  return value === undefined || value === null ? [] : [`${flag}=${value}`];
}

const server = new McpServer({
  name: 'finance-hub',
  version: '0.1.0',
});

server.registerTool(
  'sync_run',
  {
    title: 'Run SimpleFin sync',
    description:
      'Trigger a SimpleFin transaction sync. Pass connectionId to sync a single connection, or omit it to sync all active connections. Runs inline and returns real result counts.',
    inputSchema: {
      connectionId: z.string().uuid().optional(),
    },
  },
  async ({ connectionId }) => {
    try {
      const result = await artisan(
        ['sync:run', '--json', '--sync', ...opt('--connection-id', connectionId)],
        { timeoutMs: 120_000 },
      );
      return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text', text: errorMessage(err) }] };
    }
  },
);

server.registerTool(
  'sync_status',
  {
    title: 'Get sync status',
    description: 'Get the latest sync run per connection.',
    inputSchema: {},
  },
  async () => {
    try {
      const result = await artisan(['sync:status', '--json'], { timeoutMs: 30_000 });
      return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text', text: errorMessage(err) }] };
    }
  },
);

server.registerTool(
  'sync_backfill',
  {
    title: 'Backfill full transaction history',
    description:
      'Walk a connection backward in time, window by window, pulling every transaction the provider ' +
      'still has until it runs dry. Slower and heavier than sync_run — use for a new connection or ' +
      'to fill in older history, not for routine syncing. This dispatches the backfill to the queue ' +
      'and returns immediately (a full backfill can take 15+ minutes across up to 200 windows, too ' +
      'long to block a single tool call) — poll sync_status to watch its progress.',
    inputSchema: {
      connectionId: z.string().uuid(),
    },
  },
  async ({ connectionId }) => {
    try {
      const result = await artisan(
        ['sync:backfill', `--connection-id=${connectionId}`, '--json'],
        { timeoutMs: 30_000 },
      );
      return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text', text: errorMessage(err) }] };
    }
  },
);

server.registerTool(
  'list_transactions',
  {
    title: 'List transactions',
    description: 'List transactions, paginated and sortable.',
    inputSchema: {
      limit: z.number().int().positive().max(200).optional(),
      offset: z.number().int().min(0).optional(),
      sortBy: z.enum(['date', 'amount', 'name', 'merchantName']).optional(),
      order: z.enum(['asc', 'desc']).optional(),
    },
  },
  async ({ limit, offset, sortBy, order }) => {
    try {
      const result = await artisan(
        [
          'transactions:list',
          '--json',
          ...opt('--limit', limit),
          ...opt('--offset', offset),
          ...opt('--sort-by', sortBy),
          ...opt('--order', order),
        ],
        { timeoutMs: 30_000 },
      );
      return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text', text: errorMessage(err) }] };
    }
  },
);

server.registerTool(
  'recategorize_transactions',
  {
    title: 'Recategorize all transactions',
    description:
      'Re-run the category-rule engine against every transaction (backfill for newly added/changed rules).',
    inputSchema: {},
  },
  async () => {
    try {
      const result = await artisan(['categorize:recategorize', '--json', '--sync'], { timeoutMs: 300_000 });
      return { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text', text: errorMessage(err) }] };
    }
  },
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((err) => {
  console.error(errorMessage(err));
  process.exit(1);
});
