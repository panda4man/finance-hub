/**
 * Thin typed HTTP client for finance-hub's own internal API
 * (src/sync/sync.controller.ts, src/transactions/transactions.controller.ts).
 * Used by the CLI (src/cli) and the MCP server (src/mcp) — never by the Nest
 * app itself. Wire types here mirror the controllers' JSON responses, not the
 * internal Drizzle/service types.
 */

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: unknown,
  ) {
    super(`Finance Hub API request failed with status ${status}: ${describeBody(body)}`);
    this.name = 'ApiError';
  }
}

function describeBody(body: unknown): string {
  if (typeof body === 'string') {
    return body;
  }
  try {
    return JSON.stringify(body);
  } catch {
    return String(body);
  }
}

export function resolveBaseUrl(): string {
  if (process.env.FINANCE_HUB_API_URL) {
    return process.env.FINANCE_HUB_API_URL.replace(/\/+$/, '');
  }
  const port = process.env.PORT ?? '3000';
  const host = process.env.PUBLIC_HOST ?? 'localhost';
  return `http://${host}:${port}`;
}

export function getToken(): string {
  const token = process.env.INTERNAL_API_TOKEN;
  if (!token) {
    throw new Error('INTERNAL_API_TOKEN is not set in the environment — check your .env file.');
  }
  return token;
}

interface ApiFetchOptions {
  method?: 'GET' | 'POST';
  query?: Record<string, string | number | undefined>;
}

export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const url = new URL(path, resolveBaseUrl());
  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined) {
      url.searchParams.set(key, String(value));
    }
  }

  const response = await fetch(url, {
    method: options.method ?? 'GET',
    headers: { Authorization: `Bearer ${getToken()}` },
  });

  const raw = await response.text();
  let body: unknown;
  if (raw) {
    try {
      body = JSON.parse(raw);
    } catch {
      body = raw;
    }
  }

  if (!response.ok) {
    throw new ApiError(response.status, body);
  }

  return body as T;
}

export type SyncOutcome =
  | {
      connectionId: string;
      status: 'success';
      pagesFetched: number;
      added: number;
      modified: number;
      removed: number;
      accountsUpserted: number;
    }
  | { connectionId: string; status: 'failed'; error: string };

export interface SyncRunRecord {
  id: string;
  connectionId: string | null;
  trigger: 'scheduled' | 'manual' | 'webhook';
  status: 'running' | 'success' | 'partial' | 'failed';
  startedAt: string;
  finishedAt: string | null;
  cursorBefore: string | null;
  cursorAfter: string | null;
  pagesFetched: number;
  addedCount: number;
  modifiedCount: number;
  removedCount: number;
  accountsUpserted: number;
  errorCode: string | null;
  errorMessage: string | null;
  createdAt: string;
}

export interface TransactionRecord {
  id: string;
  accountId: string;
  accountName: string | null;
  date: string;
  name: string;
  merchantName: string | null;
  amount: string;
  isoCurrencyCode: string | null;
  pending: boolean;
  categorySlug: string | null;
  categoryName: string | null;
}

export interface ListTransactionsResult {
  items: TransactionRecord[];
  total: number;
  limit: number;
  offset: number;
}

export type TransactionSortField = 'date' | 'amount' | 'name' | 'merchantName';
export type SortOrder = 'asc' | 'desc';

export interface ListTransactionsQuery {
  limit?: number;
  offset?: number;
  sortBy?: TransactionSortField;
  order?: SortOrder;
}

export async function syncRun(connectionId?: string): Promise<SyncOutcome | SyncOutcome[]> {
  return apiFetch<SyncOutcome | SyncOutcome[]>('/internal/sync/run', {
    method: 'POST',
    query: { connectionId },
  });
}

export async function syncStatus(): Promise<SyncRunRecord[]> {
  return apiFetch<SyncRunRecord[]>('/internal/sync/status');
}

export async function listTransactions(
  params: ListTransactionsQuery = {},
): Promise<ListTransactionsResult> {
  return apiFetch<ListTransactionsResult>('/internal/transactions', {
    query: {
      limit: params.limit,
      offset: params.offset,
      sortBy: params.sortBy,
      order: params.order,
    },
  });
}
