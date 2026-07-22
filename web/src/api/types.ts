export interface Transaction {
  id: string;
  accountId: string;
  accountName: string | null;
  date: string;
  name: string;
  merchantName: string | null;
  amount: string;
  isoCurrencyCode: string | null;
  pending: boolean;
  categorySlug: string | null;
  categoryName: string | null;
}

export interface Account {
  id: string;
  name: string;
  officialName: string | null;
  mask: string | null;
  type: string | null;
  subtype: string | null;
}

export interface Category {
  id: string;
  slug: string;
  name: string;
  kind: string;
}

export interface ListResponse<T> {
  items: T[];
  total: number;
  limit: number;
  offset: number;
}

export interface TransactionFilters {
  dateFrom?: string;
  dateTo?: string;
  accountIds: string[];
  categorySlug?: string;
  pending?: boolean;
  search?: string;
}
