import { config } from 'dotenv';

let loaded = false;

/**
 * Loads variables from `.env` into `process.env`, once per process.
 * Never overwrites variables already set (dotenv's default behavior), so
 * container/Bridge deploys that inject env vars directly are unaffected.
 * Only the CLI and MCP entrypoints call this — the Nest app boots via its
 * own mechanism and is untouched.
 */
export function loadEnv(): void {
  if (loaded) {
    return;
  }
  loaded = true;
  config({ quiet: true });
}
