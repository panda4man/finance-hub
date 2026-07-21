import { recategorizeAll } from '../../common/http-client';
import { getFlagBoolean, ParsedArgs } from '../lib/args';
import { printJson, printRecategorizeResult } from '../lib/output';

export async function runCategorizeRecategorize(args: ParsedArgs): Promise<void> {
  const json = getFlagBoolean(args.flags, 'json');

  const result = await recategorizeAll();

  if (json) {
    printJson(result);
    return;
  }
  printRecategorizeResult(result);
}
