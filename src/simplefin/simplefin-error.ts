export interface SimplefinErrlistEntry {
  code: string;
  msg: string;
  conn_id?: string;
  account_id?: string;
}

export class SimplefinApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly errlist: SimplefinErrlistEntry[] = [],
  ) {
    super(
      errlist.length > 0
        ? errlist.map((e) => `${e.code}: ${e.msg}`).join('; ')
        : `SimpleFin request failed with HTTP ${status}`,
    );
    this.name = 'SimplefinApiError';
  }

  get code(): string | undefined {
    return this.errlist[0]?.code;
  }
}

/** Auth failures (institution- or credential-level) mean the connection needs a fresh setup token. */
export function isAuthError(err: unknown): boolean {
  if (err instanceof SimplefinApiError) {
    if (err.status === 402 || err.status === 403) {
      return true;
    }
    return err.errlist.some((e) => e.code.startsWith('gen.auth') || e.code.startsWith('con.auth'));
  }
  return false;
}

/** Transient failures (network errors, 5xx, no response at all) are worth retrying. */
export function isTransientSimplefinError(err: unknown): boolean {
  if (err instanceof SimplefinApiError) {
    return err.status >= 500 || err.errlist.some((e) => e.code.startsWith('act.failed'));
  }
  // A bare network/fetch failure (no HTTP response at all) is also transient.
  return err instanceof TypeError;
}
