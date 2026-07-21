import { z } from 'zod';

const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'production', 'test']).default('development'),
  PORT: z.coerce.number().int().positive().default(3000),
  TZ: z.string().default('UTC'),

  DATABASE_URL: z.string().min(1, 'DATABASE_URL is required'),

  ENCRYPTION_KEY: z
    .string()
    .min(1, 'ENCRYPTION_KEY is required')
    .refine((val) => {
      try {
        return Buffer.from(val, 'base64').length === 32;
      } catch {
        return false;
      }
    }, 'ENCRYPTION_KEY must be a base64-encoded 32-byte key (e.g. `openssl rand -base64 32`)'),

  INTERNAL_API_TOKEN: z.string().min(16, 'INTERNAL_API_TOKEN must be at least 16 characters'),

  SYNC_ENABLED: z
    .string()
    .default('true')
    .transform((val) => val === 'true'),
  SYNC_CRON: z.string().default('0 3 * * *'),
});

export type Env = z.infer<typeof envSchema>;

export function validateEnv(config: Record<string, unknown>): Env {
  const result = envSchema.safeParse(config);
  if (!result.success) {
    const issues = result.error.issues
      .map((issue) => `  - ${issue.path.join('.')}: ${issue.message}`)
      .join('\n');
    throw new Error(`Invalid environment configuration:\n${issues}`);
  }
  return result.data;
}
