import { Injectable } from '@nestjs/common';
import { SimplefinApiError, type SimplefinErrlistEntry } from './simplefin-error';
import type { ProviderAccount, ProviderInstitution, ProviderSyncPage } from './simplefin.types';

interface SimplefinConnectionInfo {
  conn_id: string;
  name: string;
  org_id: string;
  org_url?: string;
  sfin_url: string;
}

interface SimplefinTransaction {
  id: string;
  posted: number;
  amount: string;
  description: string;
  transacted_at?: number;
  pending?: boolean;
}

interface SimplefinAccount {
  id: string;
  name: string;
  conn_id: string;
  currency: string;
  balance: string;
  'available-balance'?: string;
  'balance-date': number;
  transactions?: SimplefinTransaction[];
}

interface SimplefinAccountSet {
  errlist: SimplefinErrlistEntry[];
  connections?: SimplefinConnectionInfo[];
  accounts: SimplefinAccount[];
}

/**
 * Thin HTTP client for the SimpleFin protocol (https://www.simplefin.org/protocol.html).
 * No SDK exists — this is a plain fetch-based implementation. No app-level
 * credential either: auth is entirely embedded in the per-connection Access
 * URL claimed once from a user-supplied setup token.
 */
@Injectable()
export class SimplefinService {
  /** Decodes a one-time setup token and claims it for a permanent Access URL. */
  async claimSetupToken(setupToken: string): Promise<string> {
    const claimUrl = Buffer.from(setupToken.trim(), 'base64').toString('utf8');

    const response = await fetch(claimUrl, {
      method: 'POST',
      headers: { 'Content-Length': '0' },
    });

    if (!response.ok) {
      throw new SimplefinApiError(response.status);
    }

    const accessUrl = (await response.text()).trim();
    if (!accessUrl) {
      throw new Error('SimpleFin claim succeeded but returned an empty Access URL');
    }
    return accessUrl;
  }

  /**
   * Fetches balances + transactions for every account under this credential
   * in a single call — the protocol has no separate per-institution or
   * paginated transaction endpoint.
   */
  async fetchAccountSet(
    credential: string,
    opts: { startDate?: Date; endDate?: Date } = {},
  ): Promise<ProviderSyncPage> {
    const credentialUrl = new URL(credential);
    const { username, password } = credentialUrl;

    const requestUrl = new URL(`${credentialUrl.origin}${credentialUrl.pathname}/accounts`);
    requestUrl.searchParams.set('version', '2');
    requestUrl.searchParams.set('pending', '1');
    if (opts.startDate) {
      requestUrl.searchParams.set('start-date', String(Math.floor(opts.startDate.getTime() / 1000)));
    }
    if (opts.endDate) {
      requestUrl.searchParams.set('end-date', String(Math.floor(opts.endDate.getTime() / 1000)));
    }

    const authHeader = `Basic ${Buffer.from(`${username}:${password}`).toString('base64')}`;
    const response = await fetch(requestUrl, {
      headers: { Authorization: authHeader },
    });

    const body: SimplefinAccountSet | undefined = await response.json().catch(() => undefined);

    if (!response.ok) {
      throw new SimplefinApiError(response.status, body?.errlist ?? []);
    }
    if (!body) {
      throw new Error('SimpleFin /accounts returned an unparseable response body');
    }

    return this.toProviderSyncPage(body);
  }

  private toProviderSyncPage(body: SimplefinAccountSet): ProviderSyncPage {
    const orgByConnId = new Map<string, SimplefinConnectionInfo>();
    for (const conn of body.connections ?? []) {
      orgByConnId.set(conn.conn_id, conn);
    }

    const institutions: ProviderInstitution[] = (body.connections ?? []).map((conn) => ({
      externalOrgId: conn.org_id,
      name: conn.name,
      url: conn.org_url,
    }));

    const accounts: ProviderAccount[] = body.accounts.map((account) => {
      const org = orgByConnId.get(account.conn_id);
      return {
        externalAccountId: account.id,
        // Falls back to conn_id when no matching `connections[]` entry was
        // returned — some SimpleFin bridges omit that array entirely.
        externalOrgId: org?.org_id ?? account.conn_id,
        name: account.name,
        isoCurrencyCode: account.currency,
        availableBalance: account['available-balance'],
        currentBalance: account.balance,
        balancesUpdatedAt: new Date(account['balance-date'] * 1000),
        transactions: (account.transactions ?? []).map((t) => {
          // SimpleFin sends posted: 0 for still-pending transactions (no
          // posted date exists yet) — fall back to transacted_at so these
          // don't land on the Unix epoch instead of their real date.
          const postedSeconds = t.posted || t.transacted_at || Math.floor(Date.now() / 1000);
          return {
            externalTransactionId: t.id,
            date: new Date(postedSeconds * 1000).toISOString().slice(0, 10),
            datetime: t.transacted_at ? new Date(t.transacted_at * 1000) : undefined,
            // SimpleFin: positive = deposit/in. This app's canonical convention
            // (established by the prior Plaid integration) is the opposite:
            // positive = money leaving the account. Negate to normalize.
            amount: (-Number(t.amount)).toFixed(2),
            name: t.description,
            pending: t.pending ?? false,
            raw: t,
          };
        }),
      };
    });

    if (institutions.length === 0 && accounts.length > 0) {
      // Some bridges omit `connections[]` — synthesize one institution per
      // distinct conn_id so accounts still resolve to *something*.
      const seen = new Set<string>();
      for (const account of body.accounts) {
        if (!seen.has(account.conn_id)) {
          seen.add(account.conn_id);
          institutions.push({ externalOrgId: account.conn_id, name: account.conn_id });
        }
      }
    }

    return { institutions, accounts };
  }
}
