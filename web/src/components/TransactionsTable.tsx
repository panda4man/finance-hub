import type { Transaction } from '../api/types';
import { formatAmount, formatDate } from '../lib/format';
import { PendingBadge } from './Badge';

interface TransactionsTableProps {
  items: Transaction[];
}

/** Desktop table (md and up). */
export function TransactionsTable({ items }: TransactionsTableProps) {
  return (
    <table className="hidden w-full text-sm md:table">
      <thead>
        <tr className="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
          <th className="px-4 py-2">Date</th>
          <th className="px-4 py-2">Description</th>
          <th className="px-4 py-2">Account</th>
          <th className="px-4 py-2">Category</th>
          <th className="px-4 py-2 text-right">Amount</th>
        </tr>
      </thead>
      <tbody>
        {items.map((txn) => (
          <tr key={txn.id} className="border-b border-gray-100 hover:bg-gray-50">
            <td className="whitespace-nowrap px-4 py-2 text-gray-600">{formatDate(txn.date)}</td>
            <td className="px-4 py-2">
              <div className="flex items-center gap-2">
                <span className="font-medium text-gray-900">{txn.merchantName ?? txn.name}</span>
                {txn.pending && <PendingBadge />}
              </div>
            </td>
            <td className="px-4 py-2 text-gray-600">{txn.accountName ?? '—'}</td>
            <td className="px-4 py-2 text-gray-600">{txn.categoryName ?? '—'}</td>
            <td className="whitespace-nowrap px-4 py-2 text-right font-medium text-gray-900">
              {formatAmount(txn.amount, txn.isoCurrencyCode)}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
