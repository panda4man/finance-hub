import { drizzle } from 'drizzle-orm/node-postgres';
import { migrate } from 'drizzle-orm/node-postgres/migrator';
import { eq } from 'drizzle-orm';
import { Pool } from 'pg';
import * as schema from '../db/schema';
import { users, connections, accounts, categories, categoryRules, transactions } from '../db/schema';
import { CategorizationService } from './categorization.service';

const TEST_DATABASE_URL =
  process.env.TEST_DATABASE_URL ?? 'postgres://finance:finance@localhost:55432/finance_hub';

describe('CategorizationService (integration)', () => {
  let pool: Pool;
  let db: ReturnType<typeof drizzle<typeof schema>>;
  let accountId: string;
  let connectionId: string;

  beforeAll(async () => {
    pool = new Pool({ connectionString: TEST_DATABASE_URL });
    db = drizzle(pool, { schema });
    await migrate(db, { migrationsFolder: './drizzle' });
  });

  afterAll(async () => {
    await pool.end();
  });

  beforeEach(async () => {
    await db.delete(transactions);
    await db.delete(accounts);
    await db.delete(connections);
    await db.delete(users);
    await db.delete(categoryRules);
    await db.delete(categories);

    const [user] = await db.insert(users).values({}).returning({ id: users.id });
    const [connection] = await db
      .insert(connections)
      .values({ userId: user.id, provider: 'simplefin', credentialEncrypted: 'test', status: 'active' })
      .returning({ id: connections.id });
    connectionId = connection.id;
    const [account] = await db
      .insert(accounts)
      .values({ connectionId, externalAccountId: `acct-${Date.now()}`, name: 'Test Checking' })
      .returning({ id: accounts.id });
    accountId = account.id;
  });

  async function makeService() {
    const service = new CategorizationService(db as never);
    await service.reloadCache();
    return service;
  }

  async function insertCategory(slug: string, sourceDetailed?: string) {
    const [row] = await db
      .insert(categories)
      .values({ slug, name: slug, sourceDetailed })
      .returning({ id: categories.id });
    return row.id;
  }

  it('returns null when no rule matches', async () => {
    const service = await makeService();
    expect(service.categorize({ name: 'Some Unrelated Merchant', amount: '10.00' })).toBeNull();
  });

  it('matches case-insensitively as a substring', async () => {
    const categoryId = await insertCategory('coffee');
    await db.insert(categoryRules).values({ pattern: 'starbucks', categoryId });

    const service = await makeService();
    expect(service.categorize({ name: 'STARBUCKS STORE #123', amount: '5.00' })).toBe(categoryId);
  });

  it('resolves rules that both match by priority, lower number wins', async () => {
    const lowPriorityCategory = await insertCategory('low_priority_cat');
    const highPriorityCategory = await insertCategory('high_priority_cat');
    // both patterns match "Coffee Shop"; priority decides which rule wins
    await db.insert(categoryRules).values([
      { pattern: 'coffee', categoryId: lowPriorityCategory, priority: 200 },
      { pattern: 'coffee shop', categoryId: highPriorityCategory, priority: 50 },
    ]);

    const service = await makeService();
    expect(service.categorize({ name: 'Coffee Shop', amount: '5.00' })).toBe(highPriorityCategory);
  });

  it('gates a rule on amount sign', async () => {
    const categoryId = await insertCategory('income_wages_test');
    await db.insert(categoryRules).values({ pattern: 'acme corp', categoryId, amountSign: 'inflow' });

    const service = await makeService();
    // outflow (positive amount) must not match an inflow-gated rule
    expect(service.categorize({ name: 'ACME CORP PAYROLL', amount: '10.00' })).toBeNull();
    // inflow (negative amount) matches
    expect(service.categorize({ name: 'ACME CORP PAYROLL', amount: '-10.00' })).toBe(categoryId);
  });

  it('prefers a sourceCategoryDetailed direct match over rule matching', async () => {
    const ruleCategoryId = await insertCategory('rule_match_cat');
    const directCategoryId = await insertCategory('direct_match_cat', 'FOOD_AND_DRINK_COFFEE');
    await db.insert(categoryRules).values({ pattern: 'coffee', categoryId: ruleCategoryId });

    const service = await makeService();
    expect(
      service.categorize({ name: 'Coffee Shop', amount: '5.00', sourceCategoryDetailed: 'FOOD_AND_DRINK_COFFEE' }),
    ).toBe(directCategoryId);
  });

  it('recategorizeAll updates categoryId across all transactions without touching userCategoryId', async () => {
    const ruleCategoryId = await insertCategory('recat_rule_cat');
    const userCategoryId = await insertCategory('recat_user_cat', undefined);
    await db.insert(categoryRules).values({ pattern: 'coffee', categoryId: ruleCategoryId });

    const [txnWithUserOverride] = await db
      .insert(transactions)
      .values({
        accountId,
        connectionId,
        externalTransactionId: `txn-a-${Date.now()}`,
        amount: '5.00',
        date: '2026-07-10',
        name: 'Coffee Shop',
        userCategoryId,
        rawPayload: {},
      })
      .returning({ id: transactions.id });

    const [txnNoMatch] = await db
      .insert(transactions)
      .values({
        accountId,
        connectionId,
        externalTransactionId: `txn-b-${Date.now()}`,
        amount: '20.00',
        date: '2026-07-10',
        name: 'Unrelated Merchant',
        rawPayload: {},
      })
      .returning({ id: transactions.id });

    const service = await makeService();
    const result = await service.recategorizeAll();
    expect(result.scanned).toBe(2);
    expect(result.updated).toBe(2);

    const [rowA] = await db.select().from(transactions).where(eq(transactions.id, txnWithUserOverride.id));
    expect(rowA.categoryId).toBe(ruleCategoryId);
    expect(rowA.userCategoryId).toBe(userCategoryId);

    const [rowB] = await db.select().from(transactions).where(eq(transactions.id, txnNoMatch.id));
    expect(rowB.categoryId).toBeNull();
  });
});
