export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export async function apiGet<T>(path: string, params?: Record<string, string | undefined>): Promise<T> {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params ?? {})) {
    if (value !== undefined && value !== '') {
      query.set(key, value);
    }
  }
  const qs = query.toString();
  const res = await fetch(`/api${path}${qs ? `?${qs}` : ''}`);

  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new ApiError(body || res.statusText, res.status);
  }
  return (await res.json()) as T;
}
