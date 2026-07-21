import { Module } from '@nestjs/common';
import { ConnectionsModule } from '../connections/connections.module';
import { SimplefinModule } from '../simplefin/simplefin.module';
import { SyncService } from './sync.service';
import { SyncScheduler } from './sync.scheduler';
import { SyncController } from './sync.controller';

@Module({
  imports: [ConnectionsModule, SimplefinModule],
  providers: [SyncService, SyncScheduler],
  controllers: [SyncController],
  exports: [SyncService],
})
export class SyncModule {}
