import type { Transaction } from '../api/types';
import { TransactionsTable } from './TransactionsTable';
import { TransactionCard } from './TransactionCard';

interface TransactionListProps {
  items: Transaction[];
  loading: boolean;
  error: string | null;
}

/** Responsive switch: table on md+, stacked cards below md. Both live in the DOM; Tailwind classes toggle visibility so no resize-listener JS is needed. */
export function TransactionList({ items, loading, error }: TransactionListProps) {
  if (error) {
    return <p className="p-6 text-sm text-red-600">Failed to load transactions: {error}</p>;
  }

  if (!loading && items.length === 0) {
    return <p className="p-6 text-sm text-gray-500">No transactions match these filters.</p>;
  }

  return (
    <div className={loading ? 'opacity-50' : undefined}>
      <TransactionsTable items={items} />
      <div className="md:hidden">
        {items.map((txn) => (
          <TransactionCard key={txn.id} txn={txn} />
        ))}
      </div>
    </div>
  );
}
