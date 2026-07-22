<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * One normalized row from a Chase checking activity CSV export. `amount` is
 * already sign-flipped to this app's schema convention (outflow=positive)
 * and `postingDate` is Y-m-d. `externalTransactionId` is the deterministic
 * dedup key — see ChaseCsvParser for the derivation.
 */
final readonly class ParsedChaseRow
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
