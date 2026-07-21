import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { loadEnv } from '../common/env';
import { ApiError, listTransactions, syncRun, syncStatus } from '../common/http-client';

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
    title: 'Run Plaid sync',
    description:
      'Trigger a Plaid transaction sync. Pass itemId to sync a single item, or omit it to sync all active items.',
    inputSchema: {
      itemId: z.string().uuid().optional(),
    },
  },
  async ({ itemId }) => {
    try {
      const result = await syncRun(itemId);
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
    description: 'Get the latest sync run per Plaid item.',
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

async function main(): Promise<void> {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((err: unknown) => {
  console.error(errorMessage(err));
  process.exit(1);
});
