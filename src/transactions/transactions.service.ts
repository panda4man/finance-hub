import { BadRequestException, Inject, Injectable } from '@nestjs/common';
import { asc, desc, eq, sql } from 'drizzle-orm';
import { DB, Database } from '../db/db.module';
import { transactionsEffective, accounts } from '../db/schema';

const SORTABLE_COLUMNS = {
  date: transactionsEffective.date,
  amount: transactionsEffective.amount,
  name: transactionsEffective.name,
  merchantName: transactionsEffective.merchantName,
} as const;
type SortField = keyof typeof SORTABLE_COLUMNS;

const DEFAULT_LIMIT = 50;
const MAX_LIMIT = 200;

export interface ListTransactionsParams {
  limit?: number;
  offset?: number;
  sortBy?: string;
  order?: string;
}

@Injectable()
export class TransactionsService {
  constructor(@Inject(DB) private readonly db: Database) {}

  async list(params: ListTransactionsParams) {
    const limit = Math.min(Math.max(params.limit ?? DEFAULT_LIMIT, 1), MAX_LIMIT);
    const offset = Math.max(params.offset ?? 0, 0);

    const sortBy = (params.sortBy ?? 'date') as SortField;
    const sortColumn = SORTABLE_COLUMNS[sortBy];
    if (!sortColumn) {
      throw new BadRequestException(
        `Invalid sortBy "${params.sortBy}". Must be one of: ${Object.keys(SORTABLE_COLUMNS).join(', ')}`,
      );
    }
    if (params.order && params.order !== 'asc' && params.order !== 'desc') {
      throw new BadRequestException('order must be "asc" or "desc"');
    }
    const direction = params.order === 'asc' ? asc : desc;

    const [{ total }] = await this.db
      .select({ total: sql<number>`count(*)`.mapWith(Number) })
      .from(transactionsEffective);

    const items = await this.db
      .select({
        id: transactionsEffective.id,
        accountId: transactionsEffective.accountId,
        accountName: accounts.name,
        date: transactionsEffective.date,
        name: transactionsEffective.name,
        merchantName: transactionsEffective.merchantName,
        amount: transactionsEffective.amount,
        isoCurrencyCode: transactionsEffective.isoCurrencyCode,
        pending: transactionsEffective.pending,
        categorySlug: transactionsEffective.categorySlug,
        categoryName: transactionsEffective.categoryName,
      })
      .from(transactionsEffective)
      .leftJoin(accounts, eq(accounts.id, transactionsEffective.accountId))
      // secondary sort on id keeps pagination stable across pages when the
      // primary sort column has ties (e.g. many transactions on the same date)
      .orderBy(direction(sortColumn), asc(transactionsEffective.id))
      .limit(limit)
      .offset(offset);

    return { items, total, limit, offset };
  }
}
