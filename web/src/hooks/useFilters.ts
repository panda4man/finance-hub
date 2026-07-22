import { useCallback, useState } from 'react';
import type { TransactionFilters } from '../api/types';

export const PAGE_SIZE = 50;

interface FiltersState {
  filters: TransactionFilters;
  offset: number;
}

function readFromUrl(): FiltersState {
  const params = new URLSearchParams(window.location.search);
  const accountIds = params.get('accountIds');
  const pending = params.get('pending');
  const offset = Number(params.get('offset') ?? '0');

  return {
    filters: {
      dateFrom: params.get('dateFrom') ?? undefined,
      dateTo: params.get('dateTo') ?? undefined,
      accountIds: accountIds ? accountIds.split(',').filter(Boolean) : [],
      categorySlug: params.get('categorySlug') ?? undefined,
      pending: pending === 'true' ? true : pending === 'false' ? false : undefined,
      search: params.get('search') ?? undefined,
    },
    offset: Number.isFinite(offset) && offset > 0 ? offset : 0,
  };
}

function writeToUrl(state: FiltersState) {
  const params = new URLSearchParams();
  const { filters, offset } = state;
  if (filters.dateFrom) params.set('dateFrom', filters.dateFrom);
  if (filters.dateTo) params.set('dateTo', filters.dateTo);
  if (filters.accountIds.length > 0) params.set('accountIds', filters.accountIds.join(','));
  if (filters.categorySlug) params.set('categorySlug', filters.categorySlug);
  if (filters.pending !== undefined) params.set('pending', String(filters.pending));
  if (filters.search) params.set('search', filters.search);
  if (offset > 0) params.set('offset', String(offset));

  const qs = params.toString();
  const url = qs ? `${window.location.pathname}?${qs}` : window.location.pathname;
  window.history.replaceState(null, '', url);
}

export function useFilters() {
  const [state, setState] = useState<FiltersState>(() => readFromUrl());

  const setFilters = useCallback((updater: (prev: TransactionFilters) => TransactionFilters) => {
    setState((prev) => {
      const next = { filters: updater(prev.filters), offset: 0 };
      writeToUrl(next);
      return next;
    });
  }, []);

  const setOffset = useCallback((offset: number) => {
    setState((prev) => {
      const next = { ...prev, offset };
      writeToUrl(next);
      return next;
    });
  }, []);

  const clearFilters = useCallback(() => {
    const next: FiltersState = { filters: { accountIds: [] }, offset: 0 };
    writeToUrl(next);
    setState(next);
  }, []);

  return { filters: state.filters, offset: state.offset, setFilters, setOffset, clearFilters };
}
