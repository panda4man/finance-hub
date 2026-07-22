import { BadRequestException, Inject, Injectable } from '@nestjs/common';
import { and, asc, desc, eq, gte, inArray, lte, sql, type SQL } from 'drizzle-orm';
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
const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

export interface ListTransactionsParams {
  limit?: number;
  offset?: number;
  sortBy?: string;
  order?: string;
  dateFrom?: string;
  dateTo?: string;
  accountIds?: string[];
  categorySlug?: string;
  pending?: boolean;
  search?: string;
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

    if (params.dateFrom && !DATE_PATTERN.test(params.dateFrom)) {
      throw new BadRequestException('dateFrom must be in YYYY-MM-DD format');
    }
    if (params.dateTo && !DATE_PATTERN.test(params.dateTo)) {
      throw new BadRequestException('dateTo must be in YYYY-MM-DD format');
    }
    if (params.dateFrom && params.dateTo && params.dateFrom > params.dateTo) {
      throw new BadRequestException('dateFrom must not be after dateTo');
    }

    const conditions: SQL[] = [];
    if (params.dateFrom) conditions.push(gte(transactionsEffective.date, params.dateFrom));
    if (params.dateTo) conditions.push(lte(transactionsEffective.date, params.dateTo));
    if (params.accountIds?.length) {
      conditions.push(inArray(transactionsEffective.accountId, params.accountIds));
    }
    if (params.categorySlug) conditions.push(eq(transactionsEffective.categorySlug, params.categorySlug));
    if (params.pending !== undefined) conditions.push(eq(transactionsEffective.pending, params.pending));
    if (params.search) {
      // ILIKE against the view rather than joining back to `transactions` for
      // the search_tsv/GIN index — simpler to keep in step with the other
      // filters below and plenty fast at personal-finance data volumes.
      conditions.push(
        sql`coalesce(${transactionsEffective.merchantName}, ${transactionsEffective.name}) ILIKE ${'%' + params.search + '%'}`,
      );
    }
    const where = conditions.length > 0 ? and(...conditions) : undefined;

    const [{ total }] = await this.db
      .select({ total: sql<number>`count(*)`.mapWith(Number) })
      .from(transactionsEffective)
      .where(where);

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
      .where(where)
      // secondary sort on id keeps pagination stable across pages when the
      // primary sort column has ties (e.g. many transactions on the same date)
      .orderBy(direction(sortColumn), asc(transactionsEffective.id))
      .limit(limit)
      .offset(offset);

    return { items, total, limit, offset };
  }
}
