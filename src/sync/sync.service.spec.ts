import { drizzle } from 'drizzle-orm/node-postgres';
import { migrate } from 'drizzle-orm/node-postgres/migrator';
import { eq } from 'drizzle-orm';
import { Pool } from 'pg';
import * as schema from '../db/schema';
import { users, institutions, plaidItems, accounts, categories, transactions, syncRuns } from '../db/schema';
import { SyncService } from './sync.service';
import type { ItemsService } from '../items/items.service';
import type { PlaidService } from '../plaid/plaid.service';

const TEST_DATABASE_URL =
  process.env.TEST_DATABASE_URL ?? 'postgres://finance:finance@localhost:55432/finance_hub';

describe('SyncService (integration)', () => {
  let pool: Pool;
  let db: ReturnType<typeof drizzle<typeof schema>>;
  let itemDbId: string;
  let accountPlaidId: string;
  let coffeeCategoryId: string;

  beforeAll(async () => {
    pool = new Pool({ connectionString: TEST_DATABASE_URL });
    db = drizzle(pool, { schema });
    await migrate(db, { migrationsFolder: './drizzle' });
  });

  afterAll(async () => {
    await pool.end();
  });

  beforeEach(async () => {
    // Clean slate for each test.
    await db.delete(transactions);
    await db.delete(syncRuns);
    await db.delete(accounts);
    await db.delete(plaidItems);
    await db.delete(institutions);
    await db.delete(users);
    await db.delete(categories);

    const [user] = await db.insert(users).values({}).returning({ id: users.id });
    const [item] = await db
      .insert(plaidItems)
      .values({
        userId: user.id,
        plaidItemId: `test-item-${Date.now()}`,
        accessTokenEncrypted: 'irrelevant-for-this-test',
        status: 'active',
      })
      .returning({ id: plaidItems.id });
    itemDbId = item.id;

    accountPlaidId = `test-account-${Date.now()}`;
    await db.insert(accounts).values({
      itemId: itemDbId,
      plaidAccountId: accountPlaidId,
      name: 'Test Checking',
      type: 'depository',
      subtype: 'checking',
    });

    const [category] = await db
      .insert(categories)
      .values({
        slug: `food_and_drink_coffee_test_${Date.now()}`,
        name: 'Coffee',
        kind: 'plaid_pfc',
        plaidPfcPrimary: 'FOOD_AND_DRINK',
        plaidPfcDetailed: 'FOOD_AND_DRINK_COFFEE',
      })
      .returning({ id: categories.id });
    coffeeCategoryId = category.id;
  });

  function makeService(plaidSyncMock: jest.Mock) {
    const fakeItems = { decryptAccessToken: async () => 'fake-access-token' } as unknown as ItemsService;
    const fakePlaid = { transactionsSync: plaidSyncMock } as unknown as PlaidService;
    return new SyncService(db as any, fakeItems, fakePlaid);
  }

  function baseTransaction(overrides: Record<string, unknown> = {}) {
    return {
      account_id: accountPlaidId,
      transaction_id: 'txn-1',
      amount: 10,
      iso_currency_code: 'USD',
      unofficial_currency_code: null,
      category: null,
      category_id: null,
      check_number: null,
      date: '2026-07-10',
      location: {},
      name: 'Coffee Shop',
      merchant_name: 'Coffee Shop',
      original_description: null,
      payment_meta: {},
      pending: false,
      pending_transaction_id: null,
      account_owner: null,
      transaction_type: undefined,
      logo_url: null,
      website: null,
      authorized_date: null,
      authorized_datetime: null,
      datetime: null,
      payment_channel: 'in store',
      personal_finance_category: { primary: 'FOOD_AND_DRINK', detailed: 'FOOD_AND_DRINK_COFFEE' },
      merchant_entity_id: null,
      ...overrides,
    };
  }

  it('resolves category_id from the Plaid personal_finance_category on insert', async () => {
    const syncMock = jest.fn().mockResolvedValueOnce({
      accounts: [],
      added: [baseTransaction()],
      modified: [],
      removed: [],
      next_cursor: 'cursor-1',
      has_more: false,
    });
    const service = makeService(syncMock);

    const result = await service.syncItem(itemDbId, 'manual');
    expect(result.status).toBe('success');

    const [txn] = await db.select().from(transactions).where(eq(transactions.plaidTransactionId, 'txn-1'));
    expect(txn.categoryId).toBe(coffeeCategoryId);
    expect(txn.amount).toBe('10.00');

    const [item] = await db.select().from(plaidItems).where(eq(plaidItems.id, itemDbId));
    expect(item.transactionsCursor).toBe('cursor-1');
  });

  it('updates Plaid-controlled fields on modify but never overwrites user-owned fields', async () => {
    const firstSync = jest.fn().mockResolvedValueOnce({
      accounts: [],
      added: [baseTransaction()],
      modified: [],
      removed: [],
      next_cursor: 'cursor-1',
      has_more: false,
    });
    await makeService(firstSync).syncItem(itemDbId, 'manual');

    // Simulate the user categorizing/annotating/hiding this transaction.
    await db
      .update(transactions)
      .set({ userCategoryId: coffeeCategoryId, userNotes: 'my custom note', isHidden: true })
      .where(eq(transactions.plaidTransactionId, 'txn-1'));

    const secondSync = jest.fn().mockResolvedValueOnce({
      accounts: [],
      added: [],
      modified: [baseTransaction({ amount: 12.5, name: 'Coffee Shop Updated' })],
      removed: [],
      next_cursor: 'cursor-2',
      has_more: false,
    });
    const result = await makeService(secondSync).syncItem(itemDbId, 'manual');
    expect(result.status).toBe('success');

    const [txn] = await db.select().from(transactions).where(eq(transactions.plaidTransactionId, 'txn-1'));
    expect(txn.amount).toBe('12.50');
    expect(txn.name).toBe('Coffee Shop Updated');
    // user-owned fields survive the modify untouched
    expect(txn.userCategoryId).toBe(coffeeCategoryId);
    expect(txn.userNotes).toBe('my custom note');
    expect(txn.isHidden).toBe(true);
  });

  it('soft-deletes removed transactions without touching user-owned fields', async () => {
    const firstSync = jest.fn().mockResolvedValueOnce({
      accounts: [],
      added: [baseTransaction()],
      modified: [],
      removed: [],
      next_cursor: 'cursor-1',
      has_more: false,
    });
    await makeService(firstSync).syncItem(itemDbId, 'manual');
    await db
      .update(transactions)
      .set({ userNotes: 'keep me' })
      .where(eq(transactions.plaidTransactionId, 'txn-1'));

    const secondSync = jest.fn().mockResolvedValueOnce({
      accounts: [],
      added: [],
      modified: [],
      removed: [{ transaction_id: 'txn-1', account_id: accountPlaidId }],
      next_cursor: 'cursor-2',
      has_more: false,
    });
    await makeService(secondSync).syncItem(itemDbId, 'manual');

    const [txn] = await db.select().from(transactions).where(eq(transactions.plaidTransactionId, 'txn-1'));
    expect(txn.removedAt).not.toBeNull();
    expect(txn.userNotes).toBe('keep me');
  });

  it('does not advance the cursor and rolls back partial writes when a later page fails', async () => {
    const syncMock = jest
      .fn()
      .mockResolvedValueOnce({
        accounts: [],
        added: [baseTransaction({ transaction_id: 'txn-page-1' })],
        modified: [],
        removed: [],
        next_cursor: 'cursor-page-1',
        has_more: true,
      })
      .mockRejectedValueOnce({
        response: { data: { error_code: 'INTERNAL_SERVER_ERROR_NONRETRY', error_message: 'boom' } },
      });
    const service = makeService(syncMock);

    await expect(service.syncItem(itemDbId, 'manual')).rejects.toBeDefined();

    const rows = await db
      .select()
      .from(transactions)
      .where(eq(transactions.plaidTransactionId, 'txn-page-1'));
    expect(rows).toHaveLength(0);

    const [item] = await db.select().from(plaidItems).where(eq(plaidItems.id, itemDbId));
    expect(item.transactionsCursor).toBeNull();

    const [run] = await db
      .select()
      .from(syncRuns)
      .where(eq(syncRuns.itemId, itemDbId));
    expect(run.status).toBe('failed');
  });

  it('marks the item login_required and does not advance the cursor on ITEM_LOGIN_REQUIRED', async () => {
    const syncMock = jest.fn().mockRejectedValueOnce({
      response: { data: { error_code: 'ITEM_LOGIN_REQUIRED', error_message: 're-auth needed' } },
    });
    const service = makeService(syncMock);

    await expect(service.syncItem(itemDbId, 'manual')).rejects.toBeDefined();

    const [item] = await db.select().from(plaidItems).where(eq(plaidItems.id, itemDbId));
    expect(item.status).toBe('login_required');
    expect(item.transactionsCursor).toBeNull();
  });
});
