import { Controller, Get, Post, Query, UseGuards } from '@nestjs/common';
import { InternalTokenGuard } from '../common/guards/internal-token.guard';
import { SyncService } from './sync.service';

@Controller('internal/sync')
@UseGuards(InternalTokenGuard)
export class SyncController {
  constructor(private readonly sync: SyncService) {}

  @Post('run')
  async run(@Query('connectionId') connectionId?: string) {
    if (connectionId) {
      return this.sync.syncConnectionSafely(connectionId, 'manual');
    }
    return this.sync.syncAllActiveConnections('manual');
  }

  @Get('status')
  async status() {
    return this.sync.getLatestRunsPerConnection();
  }
}
