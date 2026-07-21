import { syncRun, syncStatus } from '../../common/http-client';
import { getFlagBoolean, getFlagString, ParsedArgs } from '../lib/args';
import { printJson, printSyncOutcomes, printSyncStatus } from '../lib/output';

export async function runSyncRun(args: ParsedArgs): Promise<void> {
  const itemId = getFlagString(args.flags, 'item-id');
  const json = getFlagBoolean(args.flags, 'json');

  const result = await syncRun(itemId);

  if (json) {
    printJson(result);
    return;
  }
  printSyncOutcomes(result);
}

export async function runSyncStatus(args: ParsedArgs): Promise<void> {
  const json = getFlagBoolean(args.flags, 'json');

  const result = await syncStatus();

  if (json) {
    printJson(result);
    return;
  }
  printSyncStatus(result);
}
