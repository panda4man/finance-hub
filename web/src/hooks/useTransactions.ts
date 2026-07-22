import { useEffect, useState } from 'react';
import { apiGet } from '../api/client';
import type { ListResponse, Transaction, TransactionFilters } from '../api/types';
import { PAGE_SIZE } from './useFilters';

interface UseTransactionsResult {
  items: Transaction[];
  total: number;
  loading: boolean;
  error: string | null;
}

export function useTransactions(filters: TransactionFilters, offset: number): UseTransactionsResult {
  const [items, setItems] = useState<Transaction[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    apiGet<ListResponse<Transaction>>('/transactions', {
      limit: String(PAGE_SIZE),
      offset: String(offset),
      dateFrom: filters.dateFrom,
      dateTo: filters.dateTo,
      accountIds: filters.accountIds.length > 0 ? filters.accountIds.join(',') : undefined,
      categorySlug: filters.categorySlug,
      pending: filters.pending === undefined ? undefined : String(filters.pending),
      search: filters.search,
    })
      .then((res) => {
        if (cancelled) return;
        setItems(res.items);
        setTotal(res.total);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : 'Failed to load transactions');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [
    filters.dateFrom,
    filters.dateTo,
    filters.accountIds,
    filters.categorySlug,
    filters.pending,
    filters.search,
    offset,
  ]);

  return { items, total, loading, error };
}
