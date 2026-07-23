<?php

namespace Database\Seeders;

use App\Enums\DedupeStrategy;
use App\Enums\ImportColumnRole;
use App\Models\ImportTemplate;
use Illuminate\Database\Seeder;

class ImportTemplateSeeder extends Seeder
{
    /**
     * Seeds the built-in "Chase checking" template, reproducing the exact
     * header, date format, and sign convention the old hardcoded
     * ChaseCsvParser used — so external_transaction_id hashes for
     * already-imported Chase transactions stay identical.
     */
    public function run(): void
    {
        ImportTemplate::query()->updateOrCreate(
            ['name' => 'Chase checking'],
            [
                'institution_id' => null,
                'column_mapping' => [
                    ImportColumnRole::Date->value => 'Posting Date',
                    ImportColumnRole::Description->value => 'Description',
                    ImportColumnRole::Amount->value => 'Amount',
                    // Chase's debit/credit indicator lives in the "Details"
                    // column; the CSV's own "Type" column (Purchase/Deposit)
                    // is left unmapped and only survives in raw_payload.
                    ImportColumnRole::Type->value => 'Details',
                    ImportColumnRole::Balance->value => 'Balance',
                ],
                'date_format' => 'm/d/Y',
                'flip_amount_sign' => true,
                // Stored explicitly (rather than relying on GenericCsvParser's
                // default) so the dedupe shape that already-imported Chase
                // transactions were hashed with is visible and pinned here.
                'dedupe_strategy' => DedupeStrategy::Composite,
                'dedupe_columns' => [
                    ImportColumnRole::Date->value,
                    ImportColumnRole::Amount->value,
                    ImportColumnRole::Description->value,
                ],
                'header_signature' => ['Details', 'Posting Date', 'Description', 'Amount', 'Type', 'Balance', 'Check or Slip #'],
                'is_seeded' => true,
            ],
        );

        $this->command?->info('Import templates seeded.');
    }
}
