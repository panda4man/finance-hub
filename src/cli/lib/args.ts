/**
 * Minimal hand-rolled flag parser: `--flag value`, `--flag=value`, and
 * boolean flags like `--json` (no value, or next token also starts with `--`).
 */

export type FlagValue = string | boolean;

export interface ParsedArgs {
  flags: Record<string, FlagValue>;
  positional: string[];
}

export function parseArgs(argv: string[]): ParsedArgs {
  const flags: Record<string, FlagValue> = {};
  const positional: string[] = [];

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];

    if (arg.startsWith('--')) {
      const eqIndex = arg.indexOf('=');
      if (eqIndex !== -1) {
        flags[arg.slice(2, eqIndex)] = arg.slice(eqIndex + 1);
        continue;
      }
      const name = arg.slice(2);
      const next = argv[i + 1];
      if (next !== undefined && !next.startsWith('-')) {
        flags[name] = next;
        i++;
      } else {
        flags[name] = true;
      }
      continue;
    }

    if (arg.startsWith('-') && arg.length > 1) {
      flags[arg.slice(1)] = true;
      continue;
    }

    positional.push(arg);
  }

  return { flags, positional };
}

export function getFlagString(flags: ParsedArgs['flags'], name: string): string | undefined {
  const value = flags[name];
  return typeof value === 'string' ? value : undefined;
}

export function getFlagNumber(flags: ParsedArgs['flags'], name: string): number | undefined {
  const raw = getFlagString(flags, name);
  if (raw === undefined) {
    return undefined;
  }
  const value = Number(raw);
  if (Number.isNaN(value)) {
    throw new Error(`--${name} must be a number, got "${raw}"`);
  }
  return value;
}

export function getFlagBoolean(flags: ParsedArgs['flags'], name: string): boolean {
  return flags[name] === true || flags[name] === 'true';
}
