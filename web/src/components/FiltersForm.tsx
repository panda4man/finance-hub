import type { Account, Category, TransactionFilters } from '../api/types';
import { DateRangeFilter } from './filters/DateRangeFilter';
import { AccountMultiSelect } from './filters/AccountMultiSelect';
import { CategorySelect } from './filters/CategorySelect';
import { PendingToggle } from './filters/PendingToggle';
import { SearchBox } from './filters/SearchBox';

interface FiltersFormProps {
  filters: TransactionFilters;
  accounts: Account[];
  categories: Category[];
  onChange: (updater: (prev: TransactionFilters) => TransactionFilters) => void;
  onClear: () => void;
}

export function FiltersForm({ filters, accounts, categories, onChange, onClear }: FiltersFormProps) {
  return (
    <div className="space-y-4">
      <SearchBox value={filters.search} onChange={(search) => onChange((prev) => ({ ...prev, search }))} />
      <DateRangeFilter
        dateFrom={filters.dateFrom}
        dateTo={filters.dateTo}
        onChange={(dateFrom, dateTo) => onChange((prev) => ({ ...prev, dateFrom, dateTo }))}
      />
      <AccountMultiSelect
        accounts={accounts}
        selected={filters.accountIds}
        onChange={(accountIds) => onChange((prev) => ({ ...prev, accountIds }))}
      />
      <CategorySelect
        categories={categories}
        selected={filters.categorySlug}
        onChange={(categorySlug) => onChange((prev) => ({ ...prev, categorySlug }))}
      />
      <PendingToggle value={filters.pending} onChange={(pending) => onChange((prev) => ({ ...prev, pending }))} />
      <button
        type="button"
        onClick={onClear}
        className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
      >
        Clear filters
      </button>
    </div>
  );
}
