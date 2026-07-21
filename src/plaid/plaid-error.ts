export interface PlaidErrorData {
  error_type?: string;
  error_code?: string;
  error_message?: string;
}

export function getPlaidError(err: unknown): PlaidErrorData | undefined {
  const data = (err as { response?: { data?: unknown } })?.response?.data as
    | PlaidErrorData
    | undefined;
  return data && typeof data.error_code === 'string' ? data : undefined;
}

const TRANSIENT_ERROR_CODES = new Set([
  'RATE_LIMIT_EXCEEDED',
  'INTERNAL_SERVER_ERROR',
  'PRODUCT_NOT_READY',
]);

/** Transient Plaid errors (and bare network failures) are worth retrying; everything else is not. */
export function isTransientPlaidError(err: unknown): boolean {
  const data = getPlaidError(err);
  if (data) {
    return TRANSIENT_ERROR_CODES.has(data.error_code ?? '');
  }
  return !(err as { response?: unknown })?.response;
}
