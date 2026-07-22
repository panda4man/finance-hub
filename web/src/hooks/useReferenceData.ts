import { useEffect, useState } from 'react';
import { apiGet } from '../api/client';
import type { Account, Category } from '../api/types';

export function useReferenceData() {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);

  useEffect(() => {
    apiGet<Account[]>('/accounts')
      .then(setAccounts)
      .catch(() => setAccounts([]));
    apiGet<Category[]>('/categories')
      .then(setCategories)
      .catch(() => setCategories([]));
  }, []);

  return { accounts, categories };
}
