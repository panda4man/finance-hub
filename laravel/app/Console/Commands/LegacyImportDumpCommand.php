<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Services\ConnectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off import of banking_dashboard.sql — a MySQL dump of an old
 * self-built Plaid dashboard (2019-era). Only `items.user_id = 1` (the
 * legacy dump's own user id, unrelated to this app's users) is imported;
 * every other row in the dump belongs to someone else and is left
 * untouched. Imported as frozen, unlinked history: connections.status =
 * Revoked, no credential stored, never picked up by the sync scheduler.
 *
 * Ported line-for-line from src/db/import-legacy-dump.ts, with one
 * deliberate improvement: the old script re-inserted a fresh `connections`
 * row (and duplicated everything downstream) on every run. This command
 * uses firstOrCreate/updateOrCreate/insertOrIgnore throughout, so re-running
 * it against the same dump is a clean no-op.
 */
class LegacyImportDumpCommand extends Command
{
    private const TARGET_USER_ID = '1';

    private const PROVIDER = 'plaid_archive';

    private const BATCH_SIZE = 500;

    protected $signature = 'legacy:import-dump {path : Path to the mysqldump .sql file}';

    protected $description = 'Import a legacy Plaid-era mysqldump as frozen, unlinked archive history';

    public function handle(ConnectionService $connections): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        // Pass 1: `items` is tiny (~11 rows in the real dump) — grab all of
        // them, then learn which institutions/accounts we actually need from
        // the ones owned by the legacy dump's own user.
        $allItemRows = $this->collectFilteredRows($path, ['items' => fn () => true])['items'];
        $itemRows = array_values(array_filter($allItemRows, fn ($row) => $row[1] === self::TARGET_USER_ID));
        $itemIds = array_flip(array_map(fn ($row) => $row[0], $itemRows));
        $institutionIdsNeeded = array_flip(array_filter(
            array_map(fn ($row) => $row[3], $itemRows),
            fn ($value) => $value !== null,
        ));

        // Pass 2: institutions/accounts data appears *before* items in the
        // dump, so it can't be filtered inline during pass 1 — needs its own
        // pass now that itemIds/institutionIdsNeeded are known.
        $filtered = $this->collectFilteredRows($path, [
            'institutions' => fn ($row) => isset($institutionIdsNeeded[$row[0]]),
            'accounts' => fn ($row) => isset($itemIds[$row[1]]),
        ]);
        $institutionRows = $filtered['institutions'];
        $accountRows = $filtered['accounts'];
        $accountIds = array_flip(array_map(fn ($row) => $row[0], $accountRows));

        // Pass 3: transactions appears after items, but accountIds is only known now.
        $transactionRows = $this->collectFilteredRows($path, [
            'transactions' => fn ($row) => isset($accountIds[$row[1]]),
        ])['transactions'];

        $this->info(sprintf(
            'Parsed: %d item(s), %d institution(s), %d account(s), %d transaction(s)',
            count($itemRows), count($institutionRows), count($accountRows), count($transactionRows),
        ));

        $userId = $connections->getDefaultUserId();

        [$institutionCount, $connectionCount, $accountCount, $importedTransactionCount] = DB::transaction(
            fn () => $this->importRows($userId, $itemRows, $institutionRows, $accountRows, $transactionRows)
        );

        $this->info(sprintf(
            'Imported: %d institution(s), %d connection(s), %d account(s), %d transaction(s)',
            $institutionCount, $connectionCount, $accountCount, $importedTransactionCount,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<int, string|null>>  $itemRows
     * @param  list<array<int, string|null>>  $institutionRows
     * @param  list<array<int, string|null>>  $accountRows
     * @param  list<array<int, string|null>>  $transactionRows
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function importRows(
        string $userId,
        array $itemRows,
        array $institutionRows,
        array $accountRows,
        array $transactionRows,
    ): array {
        // institutions: [id, name, plaid_institution_id, has_mfa, mfa_code_type, mfa, url, image_path, logo, primary_color, ...]
        $institutionIdByLegacyId = [];
        foreach ($institutionRows as $row) {
            [$legacyId, $name, $plaidInstitutionId, , , , $url, , , $primaryColor] = $row;

            $institution = Institution::updateOrCreate(
                [
                    'provider' => self::PROVIDER,
                    'external_org_id' => $plaidInstitutionId ?? $legacyId,
                ],
                [
                    'name' => $name ?? 'Unknown institution',
                    'url' => $url,
                    'primary_color' => $primaryColor,
                ],
            );

            $institutionIdByLegacyId[$legacyId] = $institution->id;
        }

        // items: [id, user_id, plaid_item_id, institution_id, access_token, ...] — access_token is
        // a years-dead Plaid dev-environment token; deliberately not imported.
        $connectionIdByLegacyItemId = [];
        $legacyInstitutionIdByLegacyItemId = [];
        foreach ($itemRows as $row) {
            [$legacyId, , $plaidItemId, $legacyInstitutionId] = $row;
            $legacyInstitutionIdByLegacyItemId[$legacyId] = $legacyInstitutionId;

            $connection = Connection::firstOrCreate(
                [
                    'provider' => self::PROVIDER,
                    'status_detail' => 'Imported from legacy dump (plaid_item_id='.($plaidItemId ?? 'null').')',
                ],
                [
                    'user_id' => $userId,
                    'credential_encrypted' => null,
                    'status' => ConnectionStatus::Revoked,
                ],
            );

            $connectionIdByLegacyItemId[$legacyId] = $connection->id;
        }

        // accounts: [id, item_id, plaid_account_id, mask, name, official_name, subtype, type, balances, ...] —
        // `balances` is Laravel-encrypted with an APP_KEY we don't have; not recoverable, left null.
        $accountIdByLegacyId = [];
        $connectionIdByLegacyAccountId = [];
        foreach ($accountRows as $row) {
            [$legacyId, $legacyItemId, $plaidAccountId, $mask, $name, $officialName, $subtype, $type] = $row;

            $connectionId = $connectionIdByLegacyItemId[$legacyItemId] ?? null;
            if ($connectionId === null) {
                continue;
            }

            $legacyInstitutionId = $legacyInstitutionIdByLegacyItemId[$legacyItemId] ?? null;
            $institutionId = $legacyInstitutionId !== null
                ? ($institutionIdByLegacyId[$legacyInstitutionId] ?? null)
                : null;

            $account = Account::updateOrCreate(
                ['external_account_id' => $plaidAccountId],
                [
                    'connection_id' => $connectionId,
                    'institution_id' => $institutionId,
                    'name' => $name ?? 'Unknown account',
                    'official_name' => $officialName,
                    'mask' => $mask,
                    'type' => $type,
                    'subtype' => $subtype,
                ],
            );

            $accountIdByLegacyId[$legacyId] = $account->id;
            $connectionIdByLegacyAccountId[$legacyId] = $connectionId;
        }

        // transactions: [id, account_id, transaction_id, category_id, pending_transaction_id, name, amount,
        //   pending, transaction_type, iso_currency_code, unofficial_currency_code, account_owner, date,
        //   plaid_category, location, payment_meta, created_at, updated_at, deleted_at]
        $imported = 0;
        foreach (array_chunk($transactionRows, self::BATCH_SIZE) as $batch) {
            $values = [];
            foreach ($batch as $row) {
                [
                    ,
                    $legacyAccountId,
                    $transactionId,
                    $categoryId,
                    $pendingTransactionId,
                    $name,
                    $amount,
                    $pending,
                    $transactionType,
                    $isoCurrencyCode,
                    ,
                    $accountOwner,
                    $date,
                    $plaidCategory,
                    $location,
                    $paymentMeta,
                ] = $row;

                $accountId = $accountIdByLegacyId[$legacyAccountId] ?? null;
                $connectionId = $connectionIdByLegacyAccountId[$legacyAccountId] ?? null;
                if ($accountId === null || $connectionId === null) {
                    continue;
                }

                $values[] = [
                    'id' => (string) Str::uuid(),
                    'account_id' => $accountId,
                    'connection_id' => $connectionId,
                    'external_transaction_id' => $transactionId,
                    'pending' => $pending === '1',
                    'amount' => $amount,
                    'iso_currency_code' => $isoCurrencyCode,
                    'date' => $date,
                    'name' => $name ?? '(no description)',
                    'raw_payload' => json_encode([
                        'legacyCategoryId' => $categoryId,
                        'legacyPendingTransactionId' => $pendingTransactionId,
                        'transactionType' => $transactionType,
                        'accountOwner' => $accountOwner,
                        'plaidCategory' => $this->tryParseJson($plaidCategory),
                        'location' => $this->tryParseJson($location),
                        'paymentMeta' => $this->tryParseJson($paymentMeta),
                    ]),
                ];
            }

            if ($values !== []) {
                // insertOrIgnore -> `ON CONFLICT DO NOTHING` on Postgres: a plain
                // insert (or upsert(..., [])) would throw a unique-violation on
                // external_transaction_id the second time this command runs
                // against the same dump.
                DB::table('transactions')->insertOrIgnore($values);
            }
            $imported += count($values);
        }

        return [
            count($institutionIdByLegacyId),
            count($connectionIdByLegacyItemId),
            count($accountIdByLegacyId),
            $imported,
        ];
    }

    private function tryParseJson(?string $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    /**
     * Streams the dump line-by-line, keeping only rows from the requested
     * tables that pass each table's filter — the dump is well over a
     * gigabyte (mostly other users' data and Plaid's global
     * institution-logo directory), so rows are discarded as they're read
     * rather than ever collected in bulk. Table data order in the dump
     * doesn't match FK dependency order, so a full import needs multiple
     * passes (see handle()): one to learn which items/institutions/accounts
     * are relevant, then a pass that can filter inline using that knowledge.
     *
     * @param  array<string, callable(array<int, string|null>): bool>  $filters
     * @return array<string, list<array<int, string|null>>>
     */
    private function collectFilteredRows(string $path, array $filters): array
    {
        $prefixes = [];
        $result = [];
        foreach (array_keys($filters) as $table) {
            $prefixes[] = ["INSERT INTO `{$table}` VALUES ", $table];
            $result[$table] = [];
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open dump file: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                foreach ($prefixes as [$prefix, $table]) {
                    if (str_starts_with($line, $prefix)) {
                        foreach ($this->parseInsertLine($line, $table) as $row) {
                            if ($filters[$table]($row)) {
                                $result[$table][] = $row;
                            }
                        }
                        break;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * Parses every tuple out of a single `INSERT INTO `table` VALUES (...),(...);`
     * statement. mysqldump emits each statement as one physical line (string
     * literals use `\n` escapes, never raw newlines), so the whole dump can be
     * processed line-by-line via fgets() without ever holding the full file
     * in memory at once.
     *
     * @return list<array<int, string|null>>
     */
    private function parseInsertLine(string $line, string $table): array
    {
        $prefix = "INSERT INTO `{$table}` VALUES ";
        $rows = [];
        $i = strlen($prefix);
        $len = strlen($line);

        for (; ;) {
            while ($i < $len && ($line[$i] === ' ' || $line[$i] === ',')) {
                $i++;
            }
            if ($i >= $len || $line[$i] === ';') {
                break;
            }
            if ($line[$i] !== '(') {
                throw new \RuntimeException("Malformed dump: expected '(' for table {$table} at offset {$i}");
            }

            $tupleStart = $i + 1;
            $i++;
            $depth = 1;
            $inString = false;
            while ($depth > 0) {
                $ch = $line[$i] ?? null;
                if ($inString) {
                    if ($ch === '\\') {
                        $i += 2;

                        continue;
                    }
                    if ($ch === "'") {
                        $inString = false;
                    }
                    $i++;

                    continue;
                }
                if ($ch === "'") {
                    $inString = true;
                } elseif ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }
                $i++;
            }

            $tuple = substr($line, $tupleStart, ($i - 1) - $tupleStart);
            $rows[] = array_map(fn ($field) => $this->parseValue($field), $this->splitFields($tuple));
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function splitFields(string $tuple): array
    {
        $fields = [];
        $field = '';
        $inString = false;
        $len = strlen($tuple);

        for ($i = 0; $i < $len; $i++) {
            $ch = $tuple[$i];
            if ($inString) {
                if ($ch === '\\') {
                    $field .= $ch.($tuple[$i + 1] ?? '');
                    $i++;

                    continue;
                }
                $field .= $ch;
                if ($ch === "'") {
                    $inString = false;
                }

                continue;
            }
            if ($ch === "'") {
                $inString = true;
                $field .= $ch;

                continue;
            }
            if ($ch === ',') {
                $fields[] = trim($field);
                $field = '';

                continue;
            }
            $field .= $ch;
        }
        $fields[] = trim($field);

        return $fields;
    }

    private function parseValue(string $raw): ?string
    {
        if ($raw === 'NULL') {
            return null;
        }
        if (str_starts_with($raw, "'") && str_ends_with($raw, "'")) {
            return $this->unescapeMysqlString(substr($raw, 1, -1));
        }

        return $raw;
    }

    /**
     * Unescapes a mysqldump single-quoted string body (\\, \', \", \n, \r, \0, \Z).
     */
    private function unescapeMysqlString(string $s): string
    {
        $out = '';
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                $next = $s[$i + 1] ?? null;
                $i++;
                $out .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '0' => "\0",
                    'Z' => "\x1a",
                    default => $next ?? '',
                };
            } else {
                $out .= $s[$i];
            }
        }

        return $out;
    }
}
