import { Module } from '@nestjs/common';
import { ItemsModule } from '../items/items.module';
import { PlaidModule } from '../plaid/plaid.module';
import { SyncService } from './sync.service';
import { SyncScheduler } from './sync.scheduler';
import { SyncController } from './sync.controller';

@Module({
  imports: [ItemsModule, PlaidModule],
  providers: [SyncService, SyncScheduler],
  controllers: [SyncController],
  exports: [SyncService],
})
export class SyncModule {}
