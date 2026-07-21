import { drizzle } from 'drizzle-orm/node-postgres';
import { migrate } from 'drizzle-orm/node-postgres/migrator';
import { eq } from 'drizzle-orm';
import { Pool } from 'pg';
import * as schema from '../db/schema';
import { users, connections, accounts, categories, transactions, syncRuns } from '../db/schema';
import { SyncService } from './sync.service';
import { ConnectionsService } from '../connections/connections.service';
import { CryptoService } from '../crypto/crypto.service';
import { SimplefinApiError } from '../simplefin/simplefin-error';
import type { SimplefinService } from '../simplefin/simplefin.service';
import type { ProviderSyncPage } from '../simplefin/simplefin.types';

const TEST_DATABASE_URL =
  process.env.TEST_DATABASE_URL ?? 'postgres://finance:finance@localhost:55432/finance_hub';
const TEST_ENCRYPTION_KEY = Buffer.alloc(32, 7).toString('base64');

describe('SyncService (integration)', () => {
  let pool: Pool;
  let db: ReturnType<typeof drizzle<typeof schema>>;
  let crypto: CryptoService;
  let connectionId: string;
  let accountExternalId: string;

  beforeAll(async () => {
    pool = new Pool({ connectionString: TEST_DATABASE_URL });
    db = drizzle(pool, { schema });
    await migrate(db, { migrationsFolder: './drizzle' });
    crypto = new CryptoService({ get: () => TEST_ENCRYPTION_KEY } as never);
  });

  afterAll(async () => {
    await pool.end();
  });

  beforeEach(async () => {
    // Clean slate for each test.
    await db.delete(transactions);
    await db.delete(syncRuns);
    await db.delete(accounts);
    await db.delete(connections);
    await db.delete(users);
    await db.delete(categories);

    const [user] = await db.insert(users).values({}).returning({ id: users.id });
    const [connection] = await db
      .insert(connections)
      .values({
        userId: user.id,
        provider: 'simplefin',
        credentialEncrypted: crypto.encrypt('test-access-url'),
        status: 'active',
      })
      .returning({ id: connections.id });
    connectionId = connection.id;

    accountExternalId = `test-account-${Date.now()}`;
    await db.insert(accounts).values({
      connectionId,
      externalAccountId: accountExternalId,
      name: 'Test Checking',
    });
  });

  function makeService(fetchAccountSetMock: jest.Mock) {
    const fakeSimplefin = { fetchAccountSet: fetchAccountSetMock } as unknown as SimplefinService;
    const connectionsService = new ConnectionsService(db as never, crypto, fakeSimplefin);
    return new SyncService(db as never, connectionsService, fakeSimplefin);
  }

  function basePage(overrides: Partial<ProviderSyncPage['accounts'][number]['transactions'][number]> = {}): ProviderSyncPage {
    return {
      institutions: [],
      accounts: [
        {
          externalAccountId: accountExternalId,
          externalOrgId: 'org-1',
          name: 'Test Checking',
          isoCurrencyCode: 'USD',
          transactions: [
            {
              externalTransactionId: 'txn-1',
              date: '2026-07-10',
              amount: '10.00',
              name: 'Coffee Shop',
              pending: false,
              raw: {},
              ...overrides,
            },
          ],
        },
      ],
    };
  }

  it('upserts accounts and transactions on first sync, leaving categoryId unset', async () => {
    const fetchMock = jest.fn().mockResolvedValueOnce(basePage());
    const service = makeService(fetchMock);

    const result = await service.syncConnection(connectionId, 'manual');
    expect(result.status).toBe('success');

    const [txn] = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'txn-1'));
    expect(txn.amount).toBe('10.00');
    expect(txn.categoryId).toBeNull();

    const [connection] = await db.select().from(connections).where(eq(connections.id, connectionId));
    expect(connection.status).toBe('active');
    expect(connection.lastSuccessfulSyncAt).not.toBeNull();
  });

  it('updates provider-controlled fields on a later sync but never overwrites user-owned fields', async () => {
    const firstFetch = jest.fn().mockResolvedValueOnce(basePage());
    await makeService(firstFetch).syncConnection(connectionId, 'manual');

    // Simulate the user categorizing/annotating/hiding this transaction.
    const [category] = await db
      .insert(categories)
      .values({ slug: `custom_test_${Date.now()}`, name: 'Custom', kind: 'custom' })
      .returning({ id: categories.id });
    await db
      .update(transactions)
      .set({ userCategoryId: category.id, userNotes: 'my custom note', isHidden: true })
      .where(eq(transactions.externalTransactionId, 'txn-1'));

    const secondFetch = jest
      .fn()
      .mockResolvedValueOnce(basePage({ amount: '12.50', name: 'Coffee Shop Updated' }));
    const result = await makeService(secondFetch).syncConnection(connectionId, 'manual');
    expect(result.status).toBe('success');

    const [txn] = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'txn-1'));
    expect(txn.amount).toBe('12.50');
    expect(txn.name).toBe('Coffee Shop Updated');
    // user-owned fields survive the update untouched
    expect(txn.userCategoryId).toBe(category.id);
    expect(txn.userNotes).toBe('my custom note');
    expect(txn.isHidden).toBe(true);
  });

  it('creates no rows and records a failed sync run when the fetch fails', async () => {
    const fetchMock = jest.fn().mockRejectedValueOnce(new Error('boom'));
    const service = makeService(fetchMock);

    await expect(service.syncConnection(connectionId, 'manual')).rejects.toBeDefined();

    const rows = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'txn-1'));
    expect(rows).toHaveLength(0);

    const [connection] = await db.select().from(connections).where(eq(connections.id, connectionId));
    expect(connection.lastSuccessfulSyncAt).toBeNull();

    const [run] = await db.select().from(syncRuns).where(eq(syncRuns.connectionId, connectionId));
    expect(run.status).toBe('failed');
  });

  it('marks the connection login_required on an auth error, without advancing lastSuccessfulSyncAt', async () => {
    const fetchMock = jest
      .fn()
      .mockRejectedValueOnce(new SimplefinApiError(403, [{ code: 'con.auth', msg: 're-auth needed' }]));
    const service = makeService(fetchMock);

    await expect(service.syncConnection(connectionId, 'manual')).rejects.toBeDefined();

    const [connection] = await db.select().from(connections).where(eq(connections.id, connectionId));
    expect(connection.status).toBe('login_required');
    expect(connection.lastSuccessfulSyncAt).toBeNull();
  });
});
