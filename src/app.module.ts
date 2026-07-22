import { Module } from '@nestjs/common';
import { ScheduleModule } from '@nestjs/schedule';
import { ServeStaticModule } from '@nestjs/serve-static';
import { join } from 'path';
import { ConfigModule } from './config/config.module';
import { HealthModule } from './health/health.module';
import { DbModule } from './db/db.module';
import { ConnectionsModule } from './connections/connections.module';
import { SyncModule } from './sync/sync.module';
import { TransactionsModule } from './transactions/transactions.module';
import { CategorizationModule } from './categorization/categorization.module';
import { ReferenceModule } from './reference/reference.module';

@Module({
  imports: [
    ConfigModule,
    ScheduleModule.forRoot(),
    DbModule,
    HealthModule,
    ConnectionsModule,
    SyncModule,
    TransactionsModule,
    CategorizationModule,
    ReferenceModule,
    // Serves the built web/ SPA. `exclude` keeps the SPA fallback from
    // shadowing existing API/browser routes — Express 5 uses path-to-regexp
    // v8, so wildcards must use the `{*param}` form, not the old `*` form.
    ServeStaticModule.forRoot({
      rootPath: join(__dirname, '..', 'web', 'dist'),
      exclude: ['/api/{*path}', '/internal/{*path}', '/health', '/connections'],
    }),
  ],
})
export class AppModule {}
