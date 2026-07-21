import { Module } from '@nestjs/common';
import { ScheduleModule } from '@nestjs/schedule';
import { ConfigModule } from './config/config.module';
import { HealthModule } from './health/health.module';
import { DbModule } from './db/db.module';
import { ConnectionsModule } from './connections/connections.module';
import { SyncModule } from './sync/sync.module';
import { TransactionsModule } from './transactions/transactions.module';

@Module({
  imports: [
    ConfigModule,
    ScheduleModule.forRoot(),
    DbModule,
    HealthModule,
    ConnectionsModule,
    SyncModule,
    TransactionsModule,
  ],
})
export class AppModule {}
