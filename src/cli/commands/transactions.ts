import { listTransactions, SortOrder, TransactionSortField } from '../../common/http-client';
import { getFlagBoolean, getFlagNumber, getFlagString, ParsedArgs } from '../lib/args';
import { printJson, printTransactionsSummary, printTransactionsTable } from '../lib/output';

const SORT_FIELDS: TransactionSortField[] = ['date', 'amount', 'name', 'merchantName'];
const SORT_ORDERS: SortOrder[] = ['asc', 'desc'];

function parseSortBy(value: string | undefined): TransactionSortField | undefined {
  if (value === undefined) {
    return undefined;
  }
  if (!SORT_FIELDS.includes(value as TransactionSortField)) {
    throw new Error(`--sort-by must be one of: ${SORT_FIELDS.join(', ')}`);
  }
  return value as TransactionSortField;
}

function parseOrder(value: string | undefined): SortOrder | undefined {
  if (value === undefined) {
    return undefined;
  }
  if (!SORT_ORDERS.includes(value as SortOrder)) {
    throw new Error(`--order must be one of: ${SORT_ORDERS.join(', ')}`);
  }
  return value as SortOrder;
}

export async function runTransactionsList(args: ParsedArgs): Promise<void> {
  const json = getFlagBoolean(args.flags, 'json');

  const result = await listTransactions({
    limit: getFlagNumber(args.flags, 'limit'),
    offset: getFlagNumber(args.flags, 'offset'),
    sortBy: parseSortBy(getFlagString(args.flags, 'sort-by')),
    order: parseOrder(getFlagString(args.flags, 'order')),
  });

  if (json) {
    printJson(result);
    return;
  }

  printTransactionsTable(result.items);
  printTransactionsSummary(result.items.length, result.total, result.limit, result.offset);
}
