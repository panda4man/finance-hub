import type { SyncOutcome, SyncRunRecord, TransactionRecord } from '../../common/http-client';

export function printJson(data: unknown): void {
  console.log(JSON.stringify(data, null, 2));
}

function formatSyncOutcome(outcome: SyncOutcome): string {
  if (outcome.status === 'success') {
    return (
      `[${outcome.connectionId}] success — ` +
      `pages=${outcome.pagesFetched} added=${outcome.added} modified=${outcome.modified} ` +
      `removed=${outcome.removed} accounts=${outcome.accountsUpserted}`
    );
  }
  return `[${outcome.connectionId}] failed — ${outcome.error}`;
}

export function printSyncOutcomes(outcome: SyncOutcome | SyncOutcome[]): void {
  const outcomes = Array.isArray(outcome) ? outcome : [outcome];
  if (outcomes.length === 0) {
    console.log('No active items to sync.');
    return;
  }
  for (const item of outcomes) {
    console.log(formatSyncOutcome(item));
  }
}

function formatSyncRun(run: SyncRunRecord): string {
  const finished = run.finishedAt ?? 'in progress';
  return (
    `[${run.connectionId ?? 'unknown'}] ${run.status} (${run.trigger}) ` +
    `started=${run.startedAt} finished=${finished} ` +
    `added=${run.addedCount} modified=${run.modifiedCount} removed=${run.removedCount}`
  );
}

export function printSyncStatus(runs: SyncRunRecord[]): void {
  if (runs.length === 0) {
    console.log('No sync runs recorded yet.');
    return;
  }
  for (const run of runs) {
    console.log(formatSyncRun(run));
  }
}

const TRANSACTION_COLUMNS: { header: string; value: (t: TransactionRecord) => string }[] = [
  { header: 'Date', value: (t) => t.date },
  { header: 'Name', value: (t) => t.name },
  { header: 'Merchant', value: (t) => t.merchantName ?? '' },
  { header: 'Amount', value: (t) => t.amount },
  { header: 'Category', value: (t) => t.categoryName ?? '' },
  { header: 'Account', value: (t) => t.accountName ?? '' },
  { header: 'Pending', value: (t) => (t.pending ? 'yes' : 'no') },
];

export function printTransactionsTable(items: TransactionRecord[]): void {
  if (items.length === 0) {
    console.log('No transactions found.');
    return;
  }

  const widths = TRANSACTION_COLUMNS.map((col) =>
    Math.max(col.header.length, ...items.map((item) => col.value(item).length)),
  );

  const printRow = (cells: string[]) => {
    console.log(cells.map((cell, i) => cell.padEnd(widths[i])).join('  '));
  };

  printRow(TRANSACTION_COLUMNS.map((c) => c.header));
  printRow(widths.map((w) => '-'.repeat(w)));
  for (const item of items) {
    printRow(TRANSACTION_COLUMNS.map((c) => c.value(item)));
  }
}

export function printTransactionsSummary(
  shown: number,
  total: number,
  limit: number,
  offset: number,
): void {
  console.log(`\nShowing ${shown} of ${total} transactions (limit=${limit} offset=${offset})`);
}
