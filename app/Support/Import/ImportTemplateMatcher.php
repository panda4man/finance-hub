<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Models\ImportTemplate;

/**
 * Auto-detects which ImportTemplate a CSV file belongs to by exact-matching
 * its header row against each template's stored header_signature. This is
 * deliberately stricter than GenericCsvParser's own (order-independent)
 * header validation — detection should only fire when a file looks exactly
 * like a known export, not merely "close enough to parse".
 */
final class ImportTemplateMatcher
{
    /**
     * @param  list<mixed>  $headerRow
     */
    public function detectTemplate(array $headerRow): ?ImportTemplate
    {
        $normalized = array_map(static fn (mixed $cell): string => trim((string) $cell), $headerRow);

        return ImportTemplate::query()
            ->get()
            ->first(fn (ImportTemplate $template): bool => $template->header_signature === $normalized);
    }
}
