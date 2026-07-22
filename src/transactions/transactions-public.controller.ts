import { BadRequestException, Controller, Get, Query } from '@nestjs/common';
import { TransactionsService } from './transactions.service';
import { transactionsQuerySchema } from './transactions-query.dto';

/**
 * Unguarded, browser-facing sibling of `TransactionsController`
 * (`internal/transactions`, token-guarded for the MCP server/CLI). This is
 * what the web UI calls — no auth by design for this pass.
 */
@Controller('api/transactions')
export class TransactionsPublicController {
  constructor(private readonly transactions: TransactionsService) {}

  @Get()
  async list(@Query() query: Record<string, string>) {
    const parsed = transactionsQuerySchema.safeParse(query);
    if (!parsed.success) {
      throw new BadRequestException(
        parsed.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`).join('; '),
      );
    }
    return this.transactions.list(parsed.data);
  }
}
