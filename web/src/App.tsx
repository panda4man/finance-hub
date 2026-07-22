import { useState } from 'react';
import { useFilters } from './hooks/useFilters';
import { useTransactions } from './hooks/useTransactions';
import { useReferenceData } from './hooks/useReferenceData';
import { FilterBar } from './components/FilterBar';
import { FilterDrawer } from './components/FilterDrawer';
import { TransactionList } from './components/TransactionList';
import { Pagination } from './components/Pagination';

export default function App() {
  const { filters, offset, setFilters, setOffset, clearFilters } = useFilters();
  const { accounts, categories } = useReferenceData();
  const { items, total, loading, error } = useTransactions(filters, offset);
  const [drawerOpen, setDrawerOpen] = useState(false);

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="border-b border-gray-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
          <h1 className="text-lg font-semibold text-gray-900">Finance Hub</h1>
          <button
            type="button"
            onClick={() => setDrawerOpen(true)}
            className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 md:hidden"
          >
            Filters
          </button>
        </div>
      </header>

      <main className="mx-auto flex max-w-6xl gap-6 px-4 py-6">
        <FilterBar
          filters={filters}
          accounts={accounts}
          categories={categories}
          onChange={setFilters}
          onClear={clearFilters}
        />

        <FilterDrawer
          open={drawerOpen}
          onClose={() => setDrawerOpen(false)}
          filters={filters}
          accounts={accounts}
          categories={categories}
          onChange={setFilters}
          onClear={clearFilters}
        />

        <section className="min-w-0 flex-1 overflow-hidden rounded-lg border border-gray-200 bg-white">
          <TransactionList items={items} loading={loading} error={error} />
          {!error && <Pagination offset={offset} total={total} onOffsetChange={setOffset} />}
        </section>
      </main>
    </div>
  );
}
