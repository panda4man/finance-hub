import { pgView } from 'drizzle-orm/pg-core';
import { sql } from 'drizzle-orm';
import { transactions } from './transactions';
import { categories } from './categories';

/**
 * Resolves the effective category per transaction (user override wins over the
 * PFC-derived category) and pre-excludes removed/hidden rows — the single
 * surface future NL-query code should read from instead of `transactions` directly.
 */
export const transactionsEffective = pgView('v_transactions_effective').as((qb) =>
  qb
    .select({
      id: transactions.id,
      accountId: transactions.accountId,
      itemId: transactions.itemId,
      plaidTransactionId: transactions.plaidTransactionId,
      amount: transactions.amount,
      isoCurrencyCode: transactions.isoCurrencyCode,
      date: transactions.date,
      name: transactions.name,
      merchantName: transactions.merchantName,
      effectiveCategoryId: sql`coalesce(${transactions.userCategoryId}, ${transactions.categoryId})`.as(
        'effective_category_id',
      ),
      categorySlug: sql`${categories.slug}`.as('category_slug'),
      categoryName: sql`${categories.name}`.as('category_name'),
      pending: transactions.pending,
    })
    .from(transactions)
    .leftJoin(
      categories,
      sql`${categories.id} = coalesce(${transactions.userCategoryId}, ${transactions.categoryId})`,
    )
    .where(sql`${transactions.removedAt} is null and ${transactions.isHidden} = false`),
);
