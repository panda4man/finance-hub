import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { loadEnv } from '../common/env';
import { ApiError, listTransactions, recategorizeAll, syncBackfill, syncRun, syncStatus } from '../common/http-client';

loadEnv();

function errorMessage(err: unknown): string {
  if (err instanceof ApiError || err instanceof Error) {
    return err.message;
  }
  return String(err);
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
      'Trigger a SimpleFin transaction sync. Pass connectionId to sync a single connection, or omit it to sync all active connections.',
    inputSchema: {
      connectionId: z.string().uuid().optional(),
    },
  },
  async ({ connectionId }) => {
    try {
      const result = await syncRun(connectionId);
      return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text' as const, text: errorMessage(err) }] };
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
      const result = await syncStatus();
      return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text' as const, text: errorMessage(err) }] };
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
      'to fill in older history, not for routine syncing.',
    inputSchema: {
      connectionId: z.string().uuid(),
    },
  },
  async ({ connectionId }) => {
    try {
      const result = await syncBackfill(connectionId);
      return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text' as const, text: errorMessage(err) }] };
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
      const result = await listTransactions({ limit, offset, sortBy, order });
      return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text' as const, text: errorMessage(err) }] };
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
      const result = await recategorizeAll();
      return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }] };
    } catch (err) {
      return { isError: true, content: [{ type: 'text' as const, text: errorMessage(err) }] };
    }
  },
);

async function main(): Promise<void> {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((err: unknown) => {
  console.error(errorMessage(err));
  process.exit(1);
});
