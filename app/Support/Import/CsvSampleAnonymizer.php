<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\ImportColumnRole;

/**
 * Reduces a sample CSV to a small, storable snapshot for
 * ImportTemplate::sample_snapshot — the header row plus up to 4 data rows,
 * with every cell masked except the columns mapped to the "date" and "type"
 * roles (needed to read the file's date/type format, and not identifying on
 * their own). Masking replaces letters with "X" and digits with "9",
 * preserving punctuation/spacing so column shape stays recognizable without
 * exposing real values.
 */
final class CsvSampleAnonymizer
{
    private const int MAX_ROWS = 4;

    /**
     * @var list<string>
     */
    private const array SAFE_ROLES = [
        ImportColumnRole::Date->value,
        ImportColumnRole::Type->value,
    ];

    /**
     * @param  list<string>  $header
     * @param  list<array<string, mixed>>  $sampleRows  raw rows keyed by header cell, as produced by array_combine($header, $fields)
     * @param  array<string, string>  $columnMapping  role => header cell
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    public function anonymize(array $header, array $sampleRows, array $columnMapping): array
    {
        $safeHeaders = [];
        foreach (self::SAFE_ROLES as $role) {
            if (isset($columnMapping[$role])) {
                $safeHeaders[] = $columnMapping[$role];
            }
        }

        $rows = [];
        foreach (array_slice($sampleRows, 0, self::MAX_ROWS) as $sampleRow) {
            $rows[] = array_map(
                fn (string $headerCell): string => $this->cell($sampleRow, $headerCell, $safeHeaders),
                $header,
            );
        }

        return [
            'header' => $header,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $sampleRow
     * @param  list<string>  $safeHeaders
     */
    private function cell(array $sampleRow, string $headerCell, array $safeHeaders): string
    {
        $value = (string) ($sampleRow[$headerCell] ?? '');

        return in_array($headerCell, $safeHeaders, true) ? $value : $this->mask($value);
    }

    private function mask(string $value): string
    {
        return preg_replace(['/[A-Za-z]/', '/[0-9]/'], ['X', '9'], $value) ?? $value;
    }
}
