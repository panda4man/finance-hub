<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\DedupeStrategy;
use App\Enums\ImportColumnRole;
use App\Models\ImportTemplate;
use Carbon\Carbon;
use SplFileObject;

/**
 * Parses a CSV export using an ImportTemplate's column mapping instead of a
 * hardcoded per-bank format.
 *
 * external_transaction_id is derived per the template's dedupe_strategy:
 *
 *  - Composite (default): synthesized from (account, plus the normalized
 *    values of dedupe_columns — typically date/amount/description) since
 *    most bank exports have no stable transaction id.
 *  - ExternalId: the source file supplies a real unique id (mapped via the
 *    "external_id" role), used directly instead of a synthesized key.
 *
 * Either way, a 0-based occurrence index is added for exact collisions
 * within the same key group in one file, assigned in file-encounter order —
 * this also guards ExternalId against a Postgres upsert() error if a file
 * ever repeats the same id twice.
 *
 * The default composite shape ([date, amount, description]) is exactly
 * what the original Chase-only parser hardcoded — changing a template's
 * dedupe_columns changes what counts as "the same transaction" and can
 * cause already-imported rows to duplicate or stop matching, hence the
 * warning on that field in ImportTemplateResource.
 */
final class GenericCsvParser
{
    /**
     * @return array{rows: list<ParsedImportRow>, failures: list<string>}
     */
    public function parse(ImportTemplate $template, string $accountId, string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setCsvControl(',', '"', '\\');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = $file->fgetcsv();
        if ($header === false || $header === null) {
            throw new \RuntimeException('Unrecognized CSV header — the file has no header row.');
        }

        $normalizedHeader = array_map(static fn (mixed $cell): string => trim((string) $cell), $header);
        $mapping = $template->column_mapping;

        foreach ($mapping as $role => $headerName) {
            if (! in_array($headerName, $normalizedHeader, true)) {
                throw new \RuntimeException("Unrecognized CSV header — expected a column named \"{$headerName}\" (for {$role}) per the \"{$template->name}\" template.");
            }
        }

        DedupeKeyValidator::assertMapped($template);

        $columnCount = count($normalizedHeader);
        $rows = [];
        $failures = [];
        $occurrences = [];
        $lineNumber = 1;

        while (! $file->eof()) {
            $fields = $file->fgetcsv();
            $lineNumber++;

            if ($fields === false || $fields === null || $fields === [null]) {
                continue;
            }

            $paddedFields = array_pad(array_slice($fields, 0, $columnCount), $columnCount, null);
            $rawRow = array_combine($normalizedHeader, $paddedFields);

            try {
                [$date, $amount, $trimmedDescription, $balanceValue] = $this->normalizeRow(
                    $template,
                    $rawRow[$mapping[ImportColumnRole::Date->value]] ?? null,
                    $rawRow[$mapping[ImportColumnRole::Amount->value]] ?? null,
                    $rawRow[$mapping[ImportColumnRole::Description->value]] ?? null,
                    $this->mappedValue($rawRow, $mapping, ImportColumnRole::Balance),
                );

                $groupKey = $this->buildGroupKey($template, $accountId, $rawRow, $mapping, $date, $amount, $trimmedDescription);
            } catch (\Throwable $e) {
                $failures[] = "Row {$lineNumber}: {$e->getMessage()}";

                continue;
            }

            $occurrence = $occurrences[$groupKey] ?? 0;
            $occurrences[$groupKey] = $occurrence + 1;

            $detailsType = trim((string) ($this->mappedValue($rawRow, $mapping, ImportColumnRole::Type) ?? ''));

            $rows[] = new ParsedImportRow(
                externalTransactionId: 'csv:'.hash('sha256', $groupKey.'#'.$occurrence),
                postingDate: $date,
                amount: $amount,
                description: $trimmedDescription,
                detailsType: $detailsType,
                balance: $balanceValue,
                rawRow: $rawRow,
            );
        }

        return ['rows' => $rows, 'failures' => $failures];
    }

    /**
     * @return array{0: string, 1: float, 2: string, 3: ?float}
     */
    private function normalizeRow(ImportTemplate $template, mixed $rawDate, mixed $rawAmount, mixed $rawDescription, mixed $rawBalance): array
    {
        $dateStr = trim((string) $rawDate);
        $carbonDate = Carbon::createFromFormat($template->date_format, $dateStr);

        // createFromFormat silently rolls over out-of-range dates (e.g.
        // 13/45/2026) instead of failing — round-tripping the format catches
        // that instead of importing a wrong date.
        if ($carbonDate === false || $carbonDate->format($template->date_format) !== $dateStr) {
            throw new \RuntimeException("unparseable posting date \"{$dateStr}\"");
        }

        $amountStr = trim((string) $rawAmount);
        if ($amountStr === '' || ! is_numeric($amountStr)) {
            throw new \RuntimeException("unparseable amount \"{$amountStr}\"");
        }

        $amount = (float) $amountStr;
        if ($template->flip_amount_sign) {
            $amount *= -1;
        }
        $amount = round($amount, 2);

        $trimmedDescription = trim((string) $rawDescription);

        $rawBalanceStr = trim((string) $rawBalance);
        $balanceValue = ($rawBalanceStr !== '' && is_numeric($rawBalanceStr)) ? round((float) $rawBalanceStr, 2) : null;

        return [$carbonDate->toDateString(), $amount, $trimmedDescription, $balanceValue];
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @param  array<string, string>  $mapping
     */
    private function mappedValue(array $rawRow, array $mapping, ImportColumnRole $role): mixed
    {
        $headerName = $mapping[$role->value] ?? null;

        return $headerName === null ? null : ($rawRow[$headerName] ?? null);
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @param  array<string, string>  $mapping
     */
    private function buildGroupKey(
        ImportTemplate $template,
        string $accountId,
        array $rawRow,
        array $mapping,
        string $date,
        float $amount,
        string $description,
    ): string {
        if ($template->dedupe_strategy === DedupeStrategy::ExternalId) {
            $externalId = trim((string) ($this->mappedValue($rawRow, $mapping, ImportColumnRole::ExternalId) ?? ''));

            if ($externalId === '') {
                throw new \RuntimeException('missing external id value');
            }

            return $accountId.'|'.$externalId;
        }

        $dedupeColumns = $template->dedupe_columns ?: DedupeKeyValidator::DEFAULT_DEDUPE_COLUMNS;

        $parts = array_map(
            fn (string $role): string => match ($role) {
                ImportColumnRole::Date->value => $date,
                ImportColumnRole::Amount->value => number_format($amount, 2, '.', ''),
                ImportColumnRole::Description->value => $description,
                default => trim((string) ($this->mappedValue($rawRow, $mapping, ImportColumnRole::from($role)) ?? '')),
            },
            $dedupeColumns,
        );

        return $accountId.'|'.implode('|', $parts);
    }
}
