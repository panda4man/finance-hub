import { BadRequestException } from '@nestjs/common';
import { drizzle } from 'drizzle-orm/node-postgres';
import { migrate } from 'drizzle-orm/node-postgres/migrator';
import { Pool } from 'pg';
import * as schema from '../db/schema';
import { users, connections, accounts, categories, transactions } from '../db/schema';
import { TransactionsService } from './transactions.service';

const TEST_DATABASE_URL =
  process.env.TEST_DATABASE_URL ?? 'postgres://finance:finance@localhost:55432/finance_hub';

describe('TransactionsService (integration)', () => {
  let pool: Pool;
  let db: ReturnType<typeof drizzle<typeof schema>>;
  let service: TransactionsService;
  let accountAId: string;
  let accountBId: string;
  let connectionId: string;
  let groceriesId: string;
  let diningId: string;

  beforeAll(async () => {
    pool = new Pool({ connectionString: TEST_DATABASE_URL });
    db = drizzle(pool, { schema });
    await migrate(db, { migrationsFolder: './drizzle' });
    service = new TransactionsService(db as never);
  });

  afterAll(async () => {
    await pool.end();
  });

  beforeEach(async () => {
    await db.delete(transactions);
    await db.delete(accounts);
    await db.delete(connections);
    await db.delete(users);
    await db.delete(categories);

    const [user] = await db.insert(users).values({}).returning({ id: users.id });
    const [connection] = await db
      .insert(connections)
      .values({ userId: user.id, provider: 'simplefin', credentialEncrypted: 'test', status: 'active' })
      .returning({ id: connections.id });
    connectionId = connection.id;

    const [accountA] = await db
      .insert(accounts)
      .values({ connectionId, externalAccountId: `acct-a-${Date.now()}`, name: 'Checking' })
      .returning({ id: accounts.id });
    accountAId = accountA.id;

    const [accountB] = await db
      .insert(accounts)
      .values({ connectionId, externalAccountId: `acct-b-${Date.now()}`, name: 'Savings' })
      .returning({ id: accounts.id });
    accountBId = accountB.id;

    const [groceries] = await db
      .insert(categories)
      .values({ slug: 'groceries', name: 'Groceries' })
      .returning({ id: categories.id });
    groceriesId = groceries.id;

    const [dining] = await db
      .insert(categories)
      .values({ slug: 'dining', name: 'Dining' })
      .returning({ id: categories.id });
    diningId = dining.id;

    await db.insert(transactions).values([
      {
        accountId: accountAId,
        connectionId,
        externalTransactionId: `txn-1-${Date.now()}`,
        amount: '42.10',
        date: '2026-06-01',
        name: 'WHOLE FOODS MARKET',
        merchantName: 'Whole Foods',
        categoryId: groceriesId,
        pending: false,
        rawPayload: {},
      },
      {
        accountId: accountAId,
        connectionId,
        externalTransactionId: `txn-2-${Date.now()}`,
        amount: '18.50',
        date: '2026-06-15',
        name: 'CHIPOTLE ONLINE',
        merchantName: 'Chipotle',
        categoryId: diningId,
        pending: true,
        rawPayload: {},
      },
      {
        accountId: accountBId,
        connectionId,
        externalTransactionId: `txn-3-${Date.now()}`,
        amount: '9.99',
        date: '2026-07-01',
        name: 'TRADER JOES #42',
        merchantName: "Trader Joe's",
        categoryId: groceriesId,
        pending: false,
        rawPayload: {},
      },
    ]);
  });

  it('returns all transactions with no filters applied', async () => {
    const result = await service.list({});
    expect(result.total).toBe(3);
    expect(result.items).toHaveLength(3);
  });

  it('filters by date range inclusively', async () => {
    const result = await service.list({ dateFrom: '2026-06-01', dateTo: '2026-06-15' });
    expect(result.total).toBe(2);
    expect(result.items.map((i) => i.name).sort()).toEqual(['CHIPOTLE ONLINE', 'WHOLE FOODS MARKET']);
  });

  it('rejects dateFrom after dateTo', async () => {
    await expect(service.list({ dateFrom: '2026-07-01', dateTo: '2026-06-01' })).rejects.toThrow(
      BadRequestException,
    );
  });

  it('rejects malformed date filters', async () => {
    await expect(service.list({ dateFrom: '06/01/2026' })).rejects.toThrow(BadRequestException);
  });

  it('filters by accountIds with OR semantics', async () => {
    const result = await service.list({ accountIds: [accountBId] });
    expect(result.total).toBe(1);
    expect(result.items[0].name).toBe('TRADER JOES #42');
  });

  it('filters by categorySlug', async () => {
    const result = await service.list({ categorySlug: 'dining' });
    expect(result.total).toBe(1);
    expect(result.items[0].name).toBe('CHIPOTLE ONLINE');
  });

  it('filters by pending status', async () => {
    const pending = await service.list({ pending: true });
    expect(pending.total).toBe(1);
    expect(pending.items[0].name).toBe('CHIPOTLE ONLINE');

    const posted = await service.list({ pending: false });
    expect(posted.total).toBe(2);
  });

  it('filters by free-text search against merchant name, case-insensitively', async () => {
    const result = await service.list({ search: 'whole' });
    expect(result.total).toBe(1);
    expect(result.items[0].merchantName).toBe('Whole Foods');
  });

  it('returns no matches for a search term that hits nothing', async () => {
    const result = await service.list({ search: 'nonexistent-merchant-xyz' });
    expect(result.total).toBe(0);
    expect(result.items).toHaveLength(0);
  });

  it('combines filters and reports a filtered total, not the whole table, under pagination', async () => {
    const result = await service.list({ accountIds: [accountAId], limit: 1, offset: 0 });
    // only 2 of the 3 seeded transactions belong to accountA
    expect(result.total).toBe(2);
    expect(result.items).toHaveLength(1);
  });
});
