import { Controller, Get } from '@nestjs/common';
import { ReferenceService } from './reference.service';

/** Unguarded filter-dropdown data for the web UI: account and category lookups. */
@Controller('api')
export class ReferenceController {
  constructor(private readonly reference: ReferenceService) {}

  @Get('accounts')
  async accounts() {
    return this.reference.listAccounts();
  }

  @Get('categories')
  async categories() {
    return this.reference.listCategories();
  }
}
