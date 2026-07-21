import { drizzle } from 'drizzle-orm/node-postgres';
import { eq, sql } from 'drizzle-orm';
import { Pool } from 'pg';
import { categories } from './schema';
import { PFC_TAXONOMY } from './pfc-taxonomy';

function humanize(code: string): string {
  return code
    .split('_')
    .map((word) => word.charAt(0) + word.slice(1).toLowerCase())
    .join(' ');
}

async function main() {
  const connectionString = process.env.DATABASE_URL;
  if (!connectionString) {
    throw new Error('DATABASE_URL is required to seed categories');
  }

  const pool = new Pool({ connectionString });
  const db = drizzle(pool, { schema: { categories } });

  const primaries = [...new Set(PFC_TAXONOMY.map((entry) => entry.primary))];
  const parentIdByPrimary = new Map<string, string>();

  for (const primary of primaries) {
    const slug = primary.toLowerCase();
    const [row] = await db
      .insert(categories)
      .values({
        slug,
        name: humanize(primary),
        kind: 'plaid_pfc',
        plaidPfcPrimary: primary,
        plaidPfcDetailed: null,
      })
      .onConflictDoUpdate({
        target: categories.slug,
        set: { name: humanize(primary), updatedAt: sql`now()` },
      })
      .returning({ id: categories.id });
    parentIdByPrimary.set(primary, row.id);
  }

  for (const entry of PFC_TAXONOMY) {
    const slug = entry.detailed.toLowerCase();
    const name = humanize(entry.detailed.replace(`${entry.primary}_`, ''));
    await db
      .insert(categories)
      .values({
        parentId: parentIdByPrimary.get(entry.primary),
        slug,
        name,
        kind: 'plaid_pfc',
        plaidPfcPrimary: entry.primary,
        plaidPfcDetailed: entry.detailed,
      })
      .onConflictDoUpdate({
        target: categories.slug,
        set: {
          parentId: parentIdByPrimary.get(entry.primary),
          name,
          plaidPfcPrimary: entry.primary,
          updatedAt: sql`now()`,
        },
      });
  }

  const [{ count }] = await db
    .select({ count: sql<number>`count(*)`.mapWith(Number) })
    .from(categories)
    .where(eq(categories.kind, 'plaid_pfc'));
  console.log(`Category taxonomy seeded: ${primaries.length} primary, ${count} plaid_pfc total.`);

  await pool.end();
}

main().catch((err) => {
  console.error('Category seed failed:', err);
  process.exit(1);
});
