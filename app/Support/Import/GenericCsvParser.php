<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\ImportColumnRole;
use App\Models\ImportTemplate;
use Carbon\Carbon;
use SplFileObject;

/**
 * Parses a CSV export using an ImportTemplate's column mapping instead of a
 * hardcoded per-bank format.
 *
 * There is no stable transaction id or account identifier in typical bank
 * CSV exports, so external_transaction_id is synthesized from (account,
 * posting date, normalized amount, description) plus a 0-based occurrence
 * index for exact collisions within the same key group, assigned in
 * file-encounter order. This formula is intentionally NOT parameterized by
 * template — it's what the original Chase-only parser used, and changing it
 * would make every already-imported Chase transaction re-insert as a
 * duplicate on the next import.
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
            } catch (\Throwable $e) {
                $failures[] = "Row {$lineNumber}: {$e->getMessage()}";

                continue;
            }

            $groupKey = $accountId.'|'.$date.'|'.number_format($amount, 2, '.', '').'|'.$trimmedDescription;
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
}
