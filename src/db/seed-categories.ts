import { drizzle } from 'drizzle-orm/node-postgres';
import { eq, sql } from 'drizzle-orm';
import { Pool } from 'pg';
import { categories } from './schema';
import { DEFAULT_CATEGORY_TAXONOMY } from './default-category-taxonomy';

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

  const primaries = [...new Set(DEFAULT_CATEGORY_TAXONOMY.map((entry) => entry.primary))];
  const parentIdByPrimary = new Map<string, string>();

  for (const primary of primaries) {
    const slug = primary.toLowerCase();
    const [row] = await db
      .insert(categories)
      .values({
        slug,
        name: humanize(primary),
        kind: 'source_provided',
        sourcePrimary: primary,
        sourceDetailed: null,
      })
      .onConflictDoUpdate({
        target: categories.slug,
        set: { name: humanize(primary), updatedAt: sql`now()` },
      })
      .returning({ id: categories.id });
    parentIdByPrimary.set(primary, row.id);
  }

  for (const entry of DEFAULT_CATEGORY_TAXONOMY) {
    const slug = entry.detailed.toLowerCase();
    const name = humanize(entry.detailed.replace(`${entry.primary}_`, ''));
    await db
      .insert(categories)
      .values({
        parentId: parentIdByPrimary.get(entry.primary),
        slug,
        name,
        kind: 'source_provided',
        sourcePrimary: entry.primary,
        sourceDetailed: entry.detailed,
      })
      .onConflictDoUpdate({
        target: categories.slug,
        set: {
          parentId: parentIdByPrimary.get(entry.primary),
          name,
          sourcePrimary: entry.primary,
          updatedAt: sql`now()`,
        },
      });
  }

  const [{ count }] = await db
    .select({ count: sql<number>`count(*)`.mapWith(Number) })
    .from(categories)
    .where(eq(categories.kind, 'source_provided'));
  console.log(`Category taxonomy seeded: ${primaries.length} primary, ${count} source_provided total.`);

  await pool.end();
}

main().catch((err) => {
  console.error('Category seed failed:', err);
  process.exit(1);
});
