import { z } from 'zod';

export const transactionsQuerySchema = z.object({
  limit: z.coerce.number().int().positive().optional(),
  offset: z.coerce.number().int().min(0).optional(),
  sortBy: z.string().optional(),
  order: z.string().optional(),
  dateFrom: z.string().optional(),
  dateTo: z.string().optional(),
  // comma-separated list of account uuids, e.g. ?accountIds=uuid1,uuid2
  accountIds: z
    .string()
    .optional()
    .transform((val) =>
      val
        ? val
            .split(',')
            .map((id) => id.trim())
            .filter(Boolean)
        : undefined,
    ),
  categorySlug: z.string().optional(),
  pending: z
    .enum(['true', 'false'])
    .optional()
    .transform((val) => (val === undefined ? undefined : val === 'true')),
  search: z.string().optional(),
});

export type TransactionsQuery = z.infer<typeof transactionsQuerySchema>;
