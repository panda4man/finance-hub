import { Inject, Injectable, Logger, OnModuleInit } from '@nestjs/common';
import { asc, eq, gt, sql } from 'drizzle-orm';
import { DB, Database } from '../db/db.module';
import { categories, categoryRules, transactions } from '../db/schema';
import type { CategoryRuleAmountSign } from '../db/schema/category-rules';

export interface CategorizeInput {
  name: string;
  amount: string;
  sourceCategoryDetailed?: string | null;
}

export interface RecategorizeResult {
  scanned: number;
  updated: number;
}

interface CompiledRule {
  patternLower: string;
  amountSign: CategoryRuleAmountSign;
  categoryId: string;
}

const RECATEGORIZE_BATCH_SIZE = 500;

@Injectable()
export class CategorizationService implements OnModuleInit {
  private readonly logger = new Logger(CategorizationService.name);

  private rules: CompiledRule[] = [];
  private categoryIdBySourceDetailed = new Map<string, string>();
  private loaded = false;

  constructor(@Inject(DB) private readonly db: Database) {}

  async onModuleInit(): Promise<void> {
    await this.reloadCache();
  }

  async ensureLoaded(): Promise<void> {
    if (!this.loaded) {
      await this.reloadCache();
    }
  }

  /** Re-reads rules + categories from the DB into the in-memory match cache. */
  async reloadCache(): Promise<void> {
    const [ruleRows, categoryRows] = await Promise.all([
      this.db
        .select({
          pattern: categoryRules.pattern,
          amountSign: categoryRules.amountSign,
          categoryId: categoryRules.categoryId,
        })
        .from(categoryRules)
        .where(sql`${categoryRules.isActive} = true`)
        .orderBy(asc(categoryRules.priority), asc(categoryRules.createdAt)),
      this.db.select({ id: categories.id, sourceDetailed: categories.sourceDetailed }).from(categories),
    ]);

    this.rules = ruleRows.map((r) => ({
      patternLower: r.pattern.toLowerCase(),
      amountSign: r.amountSign as CategoryRuleAmountSign,
      categoryId: r.categoryId,
    }));

    this.categoryIdBySourceDetailed = new Map(
      categoryRows
        .filter((c): c is { id: string; sourceDetailed: string } => c.sourceDetailed !== null)
        .map((c) => [c.sourceDetailed, c.id]),
    );

    this.loaded = true;
    this.logger.log(`Categorization cache loaded: ${this.rules.length} active rule(s).`);
  }

  /**
   * Resolves a transaction's system-owned categoryId from the in-memory cache,
   * or null if nothing matches. Pure/synchronous — safe to call once per row
   * in a tight loop with no per-call DB I/O.
   */
  categorize(input: CategorizeInput): string | null {
    if (input.sourceCategoryDetailed) {
      const direct = this.categoryIdBySourceDetailed.get(input.sourceCategoryDetailed);
      if (direct) {
        return direct;
      }
    }

    const amount = Number(input.amount);
    const nameLower = input.name.toLowerCase();
    for (const rule of this.rules) {
      if (rule.amountSign === 'outflow' && !(amount > 0)) continue;
      if (rule.amountSign === 'inflow' && !(amount < 0)) continue;
      if (nameLower.includes(rule.patternLower)) {
        return rule.categoryId;
      }
    }

    return null;
  }

  /** Recomputes categoryId for every transaction. Never touches userCategoryId/userNotes/isHidden. */
  async recategorizeAll(): Promise<RecategorizeResult> {
    await this.ensureLoaded();

    let cursor: string | null = null;
    let scanned = 0;
    let updated = 0;

    for (;;) {
      const batch = await this.db
        .select({ id: transactions.id, name: transactions.name, amount: transactions.amount })
        .from(transactions)
        .where(cursor ? gt(transactions.id, cursor) : undefined)
        .orderBy(asc(transactions.id))
        .limit(RECATEGORIZE_BATCH_SIZE);

      if (batch.length === 0) {
        break;
      }

      for (const row of batch) {
        const categoryId = this.categorize({ name: row.name, amount: row.amount });
        await this.db
          .update(transactions)
          .set({ categoryId, updatedAt: new Date() })
          .where(eq(transactions.id, row.id));
        updated += 1;
      }

      scanned += batch.length;
      cursor = batch[batch.length - 1].id;

      if (batch.length < RECATEGORIZE_BATCH_SIZE) {
        break;
      }
    }

    this.logger.log(`Recategorize-all complete: ${scanned} scanned, ${updated} updated.`);
    return { scanned, updated };
  }
}
