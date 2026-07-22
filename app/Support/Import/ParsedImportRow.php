<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * One normalized row from a CSV import, produced by GenericCsvParser using
 * an ImportTemplate's column mapping. `amount` is already normalized to
 * this app's schema convention (outflow=positive) and `postingDate` is
 * Y-m-d. `externalTransactionId` is the deterministic dedup key — see
 * GenericCsvParser for the derivation.
 */
final readonly class ParsedImportRow
{
    /**
     * @param  array<string, mixed>  $rawRow
     */
    public function __construct(
        public string $externalTransactionId,
        public string $postingDate,
        public float $amount,
        public string $description,
        public string $detailsType,
        public ?float $balance,
        public array $rawRow,
    ) {}
}
