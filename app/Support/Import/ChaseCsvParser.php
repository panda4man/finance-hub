<?php

declare(strict_types=1);

namespace App\Support\Import;

use Carbon\Carbon;
use SplFileObject;

/**
 * Parses Chase checking "Activity" CSV exports (columns: Details, Posting
 * Date, Description, Amount, Type, Balance, Check or Slip #).
 *
 * There is no stable transaction id or account identifier in the source
 * data, so external_transaction_id is synthesized from
 * (account, posting date, sign-flipped amount, description) plus a 0-based
 * occurrence index for exact collisions within the same key group,
 * assigned in file-encounter order. Balance is deliberately excluded from
 * the key: Chase leaves it blank on the newest row of an export, and
 * including it would make that row hash differently (and re-insert as a
 * duplicate) once a later overlapping export shows it with a real balance.
 * Because Chase always lists a given account's history in the same
 * relative order, the occurrence index alone stays stable across
 * re-exports.
 */
final class ChaseCsvParser
{
    private const EXPECTED_HEADER = ['Details', 'Posting Date', 'Description', 'Amount', 'Type', 'Balance', 'Check or Slip #'];

    /**
     * @return array{rows: list<ParsedChaseRow>, failures: list<string>}
     */
    public function parse(string $accountId, string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setCsvControl(',', '"', '\\');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = $file->fgetcsv();
        if ($header === false || $header === null || array_map('trim', $header) !== self::EXPECTED_HEADER) {
            throw new \RuntimeException('Unrecognized CSV header — expected a Chase checking activity export.');
        }

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

            [$details, $postingDate, $description, $amount, $type, $balance] = array_pad($fields, 6, null);

            try {
                [$date, $flippedAmount, $trimmedDescription, $balanceValue] = $this->normalizeRow(
                    $postingDate,
                    $amount,
                    $description,
                    $balance,
                );
            } catch (\Throwable $e) {
                $failures[] = "Row {$lineNumber}: {$e->getMessage()}";

                continue;
            }

            $groupKey = $accountId.'|'.$date.'|'.number_format($flippedAmount, 2, '.', '').'|'.$trimmedDescription;
            $occurrence = $occurrences[$groupKey] ?? 0;
            $occurrences[$groupKey] = $occurrence + 1;

            $rows[] = new ParsedChaseRow(
                externalTransactionId: 'csv:'.hash('sha256', $groupKey.'#'.$occurrence),
                postingDate: $date,
                amount: $flippedAmount,
                description: $trimmedDescription,
                detailsType: trim((string) $details),
                balance: $balanceValue,
                rawRow: [
                    'Details' => $details,
                    'Posting Date' => $postingDate,
                    'Description' => $description,
                    'Amount' => $amount,
                    'Type' => $type,
                    'Balance' => $balance,
                ],
            );
        }

        return ['rows' => $rows, 'failures' => $failures];
    }

    /**
     * @return array{0: string, 1: float, 2: string, 3: ?float}
     */
    private function normalizeRow(mixed $postingDate, mixed $amount, mixed $description, mixed $balance): array
    {
        $rawDate = trim((string) $postingDate);
        $carbonDate = Carbon::createFromFormat('m/d/Y', $rawDate);

        // createFromFormat silently rolls over out-of-range dates (e.g.
        // 13/45/2026) instead of failing — round-tripping the format catches
        // that instead of importing a wrong date.
        if ($carbonDate === false || $carbonDate->format('m/d/Y') !== $rawDate) {
            throw new \RuntimeException("unparseable posting date \"{$rawDate}\"");
        }

        $rawAmount = trim((string) $amount);
        if ($rawAmount === '' || ! is_numeric($rawAmount)) {
            throw new \RuntimeException("unparseable amount \"{$rawAmount}\"");
        }

        $flippedAmount = round(-1 * (float) $rawAmount, 2);
        $trimmedDescription = trim((string) $description);

        $rawBalance = trim((string) $balance);
        $balanceValue = ($rawBalance !== '' && is_numeric($rawBalance)) ? round((float) $rawBalance, 2) : null;

        return [$carbonDate->toDateString(), $flippedAmount, $trimmedDescription, $balanceValue];
    }
}
