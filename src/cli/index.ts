import { loadEnv } from '../common/env';
import { ApiError } from '../common/http-client';
import { runSyncRun, runSyncStatus } from './commands/sync';
import { runTransactionsList } from './commands/transactions';
import { runCategorizeRecategorize } from './commands/categorize';
import { parseArgs } from './lib/args';

loadEnv();

const HELP = `finance-hub CLI

Usage:
  npm run cli -- sync run [--item-id <uuid>] [--json]
  npm run cli -- sync status [--json]
  npm run cli -- transactions list [--limit N] [--offset N] [--sort-by date|amount|name|merchantName] [--order asc|desc] [--json]
  npm run cli -- categorize recategorize [--json]
  npm run cli -- --help
`;

async function main(): Promise<void> {
  const argv = process.argv.slice(2);

  if (argv.length === 0 || argv[0] === '-h' || argv[0] === '--help') {
    console.log(HELP);
    return;
  }

  const [group, action, ...rest] = argv;
  const args = parseArgs(rest);

  if (group === 'sync' && action === 'run') {
    return runSyncRun(args);
  }
  if (group === 'sync' && action === 'status') {
    return runSyncStatus(args);
  }
  if (group === 'transactions' && action === 'list') {
    return runTransactionsList(args);
  }
  if (group === 'categorize' && action === 'recategorize') {
    return runCategorizeRecategorize(args);
  }

  console.error(`Unknown command: ${argv.join(' ')}\n`);
  console.log(HELP);
  process.exitCode = 1;
}

main().catch((err: unknown) => {
  if (err instanceof ApiError) {
    console.error(err.message);
  } else if (err instanceof Error) {
    console.error(err.message);
  } else {
    console.error(err);
  }
  process.exitCode = 1;
});
