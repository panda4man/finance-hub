import { drizzle } from 'drizzle-orm/node-postgres';
import { sql } from 'drizzle-orm';
import { Pool } from 'pg';
import { categories, categoryRules } from './schema';
import { DEFAULT_CATEGORY_RULES } from './default-category-rules';

async function main() {
  const connectionString = process.env.DATABASE_URL;
  if (!connectionString) {
    throw new Error('DATABASE_URL is required to seed category rules');
  }

  const pool = new Pool({ connectionString });
  const db = drizzle(pool, { schema: { categories, categoryRules } });

  const categoryRows = await db.select({ id: categories.id, slug: categories.slug }).from(categories);
  const categoryIdBySlug = new Map(categoryRows.map((c) => [c.slug, c.id]));

  let seeded = 0;
  let skipped = 0;
  for (const rule of DEFAULT_CATEGORY_RULES) {
    const categoryId = categoryIdBySlug.get(rule.categorySlug);
    if (!categoryId) {
      console.warn(`Skipping rule "${rule.pattern}": unknown category slug "${rule.categorySlug}"`);
      skipped += 1;
      continue;
    }
    await db
      .insert(categoryRules)
      .values({
        pattern: rule.pattern,
        matchField: 'name',
        matchType: 'substring',
        amountSign: rule.amountSign ?? 'any',
        categoryId,
        priority: rule.priority ?? 100,
        source: 'default',
      })
      .onConflictDoUpdate({
        target: [categoryRules.pattern, categoryRules.matchField],
        set: {
          amountSign: rule.amountSign ?? 'any',
          categoryId,
          priority: rule.priority ?? 100,
          source: 'default',
          updatedAt: sql`now()`,
        },
      });
    seeded += 1;
  }

  console.log(`Category rules seeded: ${seeded} upserted, ${skipped} skipped (unknown category).`);
  await pool.end();
}

main().catch((err) => {
  console.error('Category rule seed failed:', err);
  process.exit(1);
});
