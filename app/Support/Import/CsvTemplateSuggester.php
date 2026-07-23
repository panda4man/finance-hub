<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\DedupeStrategy;
use App\Enums\ImportColumnRole;

/**
 * Suggests an ImportTemplate's header_signature, column_mapping, and
 * idempotency key (dedupe_strategy/dedupe_columns) from a sample CSV, so
 * ImportTemplateResource's create form can be prefilled instead of built
 * entirely by hand.
 *
 * These are suggestions only — the create form leaves every field editable
 * and required, exactly as it is today for a fully manual template.
 */
final class CsvTemplateSuggester
{
    /**
     * @var array<string, list<string>>
     */
    private const ROLE_ALIASES = [
        'date' => ['date', 'posting date', 'post date', 'transaction date'],
        'description' => ['description', 'memo', 'details'],
        'amount' => ['amount', 'debit', 'credit'],
        'type' => ['type', 'details type', 'transaction type'],
        'balance' => ['balance', 'running balance'],
        'external_id' => ['confirmation #', 'confirmation number', 'reference', 'reference number', 'transaction id', 'id'],
    ];

    /**
     * @param  list<mixed>  $headerRow
     * @param  list<array<string, mixed>>  $sampleRows  raw rows keyed by header cell, as produced by array_combine($headerRow, $fields)
     * @return array{header_signature: list<string>, column_mapping: array<string, string>, dedupe_strategy: DedupeStrategy, dedupe_columns: list<string>}
     */
    public function analyze(array $headerRow, array $sampleRows): array
    {
        $normalizedHeader = array_values(array_map(static fn (mixed $cell): string => trim((string) $cell), $headerRow));

        $columnMapping = $this->suggestColumnMapping($normalizedHeader);
        [$dedupeStrategy, $dedupeColumns] = $this->suggestDedupeStrategy($columnMapping, $sampleRows);

        return [
            'header_signature' => $normalizedHeader,
            'column_mapping' => $columnMapping,
            'dedupe_strategy' => $dedupeStrategy,
            'dedupe_columns' => $dedupeColumns,
        ];
    }

    /**
     * @param  list<string>  $normalizedHeader
     * @return array<string, string>
     */
    private function suggestColumnMapping(array $normalizedHeader): array
    {
        $mapping = [];
        $usedHeaders = [];

        foreach (self::ROLE_ALIASES as $role => $aliases) {
            foreach ($normalizedHeader as $headerCell) {
                if (in_array($headerCell, $usedHeaders, true)) {
                    continue;
                }

                if (in_array(strtolower($headerCell), $aliases, true)) {
                    $mapping[$role] = $headerCell;
                    $usedHeaders[] = $headerCell;

                    continue 2;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, string>  $columnMapping
     * @param  list<array<string, mixed>>  $sampleRows
     * @return array{0: DedupeStrategy, 1: list<string>}
     */
    private function suggestDedupeStrategy(array $columnMapping, array $sampleRows): array
    {
        $externalIdHeader = $columnMapping[ImportColumnRole::ExternalId->value] ?? null;

        if ($externalIdHeader !== null && $sampleRows !== [] && $this->columnIsUniqueAndComplete($externalIdHeader, $sampleRows)) {
            return [DedupeStrategy::ExternalId, []];
        }

        return [DedupeStrategy::Composite, DedupeKeyValidator::DEFAULT_DEDUPE_COLUMNS];
    }

    /**
     * @param  list<array<string, mixed>>  $sampleRows
     */
    private function columnIsUniqueAndComplete(string $header, array $sampleRows): bool
    {
        $values = array_map(static fn (array $row): string => trim((string) ($row[$header] ?? '')), $sampleRows);

        if (in_array('', $values, true)) {
            return false;
        }

        return count($values) === count(array_unique($values));
    }
}
