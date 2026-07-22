import type { Account, Category, TransactionFilters } from '../api/types';
import { FiltersForm } from './FiltersForm';

interface FilterBarProps {
  filters: TransactionFilters;
  accounts: Account[];
  categories: Category[];
  onChange: (updater: (prev: TransactionFilters) => TransactionFilters) => void;
  onClear: () => void;
}

/** Persistent desktop sidebar (md and up). Hidden on mobile in favor of FilterDrawer. */
export function FilterBar(props: FilterBarProps) {
  return (
    <aside className="hidden w-64 shrink-0 md:block">
      <div className="sticky top-4 rounded-lg border border-gray-200 bg-white p-4">
        <h2 className="mb-3 text-sm font-semibold text-gray-900">Filters</h2>
        <FiltersForm {...props} />
      </div>
    </aside>
  );
}
