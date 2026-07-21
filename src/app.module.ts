import { Module } from '@nestjs/common';
import { ScheduleModule } from '@nestjs/schedule';
import { ConfigModule } from './config/config.module';
import { HealthModule } from './health/health.module';
import { DbModule } from './db/db.module';
import { LinkModule } from './link/link.module';
import { SyncModule } from './sync/sync.module';
import { TransactionsModule } from './transactions/transactions.module';
import { SetupModule } from './setup/setup.module';

@Module({
  imports: [
    ConfigModule,
    ScheduleModule.forRoot(),
    DbModule,
    HealthModule,
    LinkModule,
    SyncModule,
    TransactionsModule,
    SetupModule,
  ],
})
export class AppModule {}
