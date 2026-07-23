<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\DedupeStrategy;
use App\Enums\ImportColumnRole;
use App\Models\ImportTemplate;

/**
 * Verifies that an ImportTemplate's column_mapping covers everything a row
 * needs to be parsed and deduplicated:
 *
 *  - Core roles (date, amount, description) are always required — parsing
 *    itself depends on them regardless of dedupe_strategy or which roles an
 *    admin happens to have picked for dedupe_columns.
 *  - Dedupe roles depend on dedupe_strategy: external_id needs the
 *    "external_id" role mapped; composite needs every role in
 *    dedupe_columns (defaulting to the core three) mapped.
 *
 * Without this, a role missing from column_mapping fails silently:
 * mappedValue() in GenericCsvParser returns null for it, which either folds
 * a blank string into a composite key (wrong dedup collisions, no error) or,
 * for external_id, only surfaces as a per-row failure deep in parsing
 * instead of a single clear error before the import starts. Core roles have
 * the same failure mode via normalizeRow()'s Carbon/numeric parsing.
 */
final class DedupeKeyValidator
{
    /**
     * @var list<string>
     */
    public const DEFAULT_DEDUPE_COLUMNS = [
        ImportColumnRole::Date->value,
        ImportColumnRole::Amount->value,
        ImportColumnRole::Description->value,
    ];

    /**
     * Roles every row must be parseable by, independent of dedupe_strategy.
     *
     * @var list<string>
     */
    public const CORE_ROLES = self::DEFAULT_DEDUPE_COLUMNS;

    /**
     * Role values the active dedupe_strategy needs (not necessarily valid
     * ImportColumnRole cases — a stale or hand-edited dedupe_columns entry
     * may name a role that no longer exists, which must surface as
     * "missing" in missingRoles() below, not blow up here).
     *
     * @param  list<string>|null  $dedupeColumns
     * @return list<string>
     */
    public static function requiredDedupeRoles(DedupeStrategy $strategy, ?array $dedupeColumns): array
    {
        if ($strategy === DedupeStrategy::ExternalId) {
            return [ImportColumnRole::ExternalId->value];
        }

        return array_values($dedupeColumns ?: self::DEFAULT_DEDUPE_COLUMNS);
    }

    /**
     * @param  array<string, string>  $columnMapping
     * @return list<string>
     */
    public static function missingCoreRoles(array $columnMapping): array
    {
        return self::missingFrom(self::CORE_ROLES, $columnMapping);
    }

    /**
     * @param  list<string>|null  $dedupeColumns
     * @param  array<string, string>  $columnMapping
     * @return list<string>
     */
    public static function missingDedupeRoles(DedupeStrategy $strategy, ?array $dedupeColumns, array $columnMapping): array
    {
        return self::missingFrom(self::requiredDedupeRoles($strategy, $dedupeColumns), $columnMapping);
    }

    /**
     * @param  list<string>|null  $dedupeColumns
     * @param  array<string, string>  $columnMapping
     * @return list<string>
     */
    private static function missingFrom(array $roles, array $columnMapping): array
    {
        return array_values(array_filter(
            $roles,
            static fn (string $role): bool => trim((string) ($columnMapping[$role] ?? '')) === '',
        ));
    }

    /**
     * @throws \RuntimeException naming the missing role(s); no-op when valid
     */
    public static function assertMapped(ImportTemplate $template): void
    {
        $columnMapping = $template->column_mapping ?? [];
        $missingCore = self::missingCoreRoles($columnMapping);
        $missingDedupe = self::missingDedupeRoles($template->dedupe_strategy, $template->dedupe_columns, $columnMapping);

        if ($missingCore === [] && $missingDedupe === []) {
            return;
        }

        $dedupeLabel = $template->dedupe_strategy === DedupeStrategy::ExternalId
            ? 'external-id idempotency key'
            : 'composite idempotency key';

        $parts = [];
        if ($missingCore !== []) {
            $parts[] = 'the CSV parsing column(s) ['.implode(', ', $missingCore).']';
        }
        if ($missingDedupe !== []) {
            $parts[] = "the {$dedupeLabel} column(s) [".implode(', ', $missingDedupe).']';
        }

        throw new \RuntimeException(
            "The \"{$template->name}\" template requires ".implode(' and ', $parts).', '
            .'but they are not mapped in the template\'s column mapping. '
            .'Add them to the column mapping before importing.'
        );
    }
}
