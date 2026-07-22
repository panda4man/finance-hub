import type { Account, Category, TransactionFilters } from '../api/types';
import { FiltersForm } from './FiltersForm';

interface FilterDrawerProps {
  open: boolean;
  onClose: () => void;
  filters: TransactionFilters;
  accounts: Account[];
  categories: Category[];
  onChange: (updater: (prev: TransactionFilters) => TransactionFilters) => void;
  onClear: () => void;
}

/** Mobile slide-over sheet (below md). Opened via a "Filters" button. */
export function FilterDrawer({ open, onClose, ...formProps }: FilterDrawerProps) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 md:hidden">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="absolute inset-y-0 right-0 w-full max-w-xs overflow-y-auto bg-white p-4 shadow-xl">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md px-2 py-1 text-sm font-medium text-gray-500 hover:bg-gray-100"
          >
            Done
          </button>
        </div>
        <FiltersForm {...formProps} />
      </div>
    </div>
  );
}
