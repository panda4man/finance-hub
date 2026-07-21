import { Inject, Injectable, Logger, OnModuleInit } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { SchedulerRegistry } from '@nestjs/schedule';
import { CronJob } from 'cron';
import { inArray } from 'drizzle-orm';
import type { Env } from '../config/env.validation';
import { DB, Database } from '../db/db.module';
import { connections } from '../db/schema';
import { SyncService } from './sync.service';

const NIGHTLY_SYNC_JOB_NAME = 'nightly-sync';
const STALE_SYNC_THRESHOLD_MS = 24 * 60 * 60 * 1000;

@Injectable()
export class SyncScheduler implements OnModuleInit {
  private readonly logger = new Logger(SyncScheduler.name);
  private running = false;

  constructor(
    private readonly config: ConfigService<Env, true>,
    private readonly schedulerRegistry: SchedulerRegistry,
    private readonly sync: SyncService,
    @Inject(DB) private readonly db: Database,
  ) {}

  async onModuleInit(): Promise<void> {
    const enabled = this.config.get<boolean>('SYNC_ENABLED');
    if (!enabled) {
      this.logger.log('Nightly sync disabled (SYNC_ENABLED=false)');
      return;
    }

    const cronExpression = this.config.get<string>('SYNC_CRON');
    const job = new CronJob(cronExpression, () => {
      void this.runScheduledSync();
    });
    this.schedulerRegistry.addCronJob(NIGHTLY_SYNC_JOB_NAME, job as never);
    job.start();
    this.logger.log(`Nightly sync scheduled: "${cronExpression}"`);

    await this.runCatchUpSyncIfStale();
  }

  private async runScheduledSync(): Promise<void> {
    if (this.running) {
      this.logger.warn('Skipping sync run: previous run is still in progress');
      return;
    }

    this.running = true;
    try {
      const results = await this.sync.syncAllActiveConnections('scheduled');
      const summary = results.map((r) => `${r.connectionId.slice(0, 8)}:${r.status}`).join(', ');
      this.logger.log(`Scheduled sync complete for ${results.length} connection(s): ${summary}`);
    } catch (err) {
      this.logger.error(`Scheduled sync run failed unexpectedly: ${(err as Error).message}`);
    } finally {
      this.running = false;
    }
  }

  private async runCatchUpSyncIfStale(): Promise<void> {
    const activeConnections = await this.db
      .select({ lastSuccessfulSyncAt: connections.lastSuccessfulSyncAt })
      .from(connections)
      .where(inArray(connections.status, ['active', 'pending_expiration']));

    const now = Date.now();
    const isStale = activeConnections.some(
      (connection) =>
        !connection.lastSuccessfulSyncAt ||
        now - connection.lastSuccessfulSyncAt.getTime() > STALE_SYNC_THRESHOLD_MS,
    );

    if (activeConnections.length > 0 && isStale) {
      this.logger.log('Last successful sync is stale; running catch-up sync on boot');
      void this.runScheduledSync();
    }
  }
}
