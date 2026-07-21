/**
 * Provider-agnostic shapes SyncService consumes. A future provider only
 * needs a service that produces these — no schema or SyncService changes.
 */

export interface ProviderInstitution {
  externalOrgId: string;
  name: string;
  url?: string;
}

export interface ProviderTransaction {
  externalTransactionId: string;
  date: string; // YYYY-MM-DD
  datetime?: Date;
  /** Canonical convention: positive = money leaving the account (debit/outflow). */
  amount: string;
  name: string;
  pending: boolean;
  raw: unknown;
}

export interface ProviderAccount {
  externalAccountId: string;
  externalOrgId: string;
  name: string;
  isoCurrencyCode?: string;
  availableBalance?: string;
  currentBalance?: string;
  balancesUpdatedAt?: Date;
  transactions: ProviderTransaction[];
}

export interface ProviderSyncPage {
  institutions: ProviderInstitution[];
  accounts: ProviderAccount[];
}
