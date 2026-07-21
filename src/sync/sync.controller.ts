import { Controller, Get, Post, Query, UseGuards } from '@nestjs/common';
import { InternalTokenGuard } from '../common/guards/internal-token.guard';
import { SyncService } from './sync.service';

@Controller('internal/sync')
@UseGuards(InternalTokenGuard)
export class SyncController {
  constructor(private readonly sync: SyncService) {}

  @Post('run')
  async run(@Query('itemId') itemId?: string) {
    if (itemId) {
      return this.sync.syncItemSafely(itemId, 'manual');
    }
    return this.sync.syncAllActiveItems('manual');
  }

  @Get('status')
  async status() {
    return this.sync.getLatestRunsPerItem();
  }
}
