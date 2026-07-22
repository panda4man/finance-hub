import type { Transaction } from '../api/types';
import { formatAmount, formatDate } from '../lib/format';
import { PendingBadge } from './Badge';

interface TransactionCardProps {
  txn: Transaction;
}

export function TransactionCard({ txn }: TransactionCardProps) {
  return (
    <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <span className="truncate font-medium text-gray-900">{txn.merchantName ?? txn.name}</span>
          {txn.pending && <PendingBadge />}
        </div>
        <p className="mt-0.5 truncate text-xs text-gray-500">
          {formatDate(txn.date)} · {txn.accountName ?? '—'}
          {txn.categoryName ? ` · ${txn.categoryName}` : ''}
        </p>
      </div>
      <span className="shrink-0 font-medium text-gray-900">{formatAmount(txn.amount, txn.isoCurrencyCode)}</span>
    </div>
  );
}
