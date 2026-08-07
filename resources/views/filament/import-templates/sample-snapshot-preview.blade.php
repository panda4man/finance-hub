@php
    $snapshot = $record->sample_snapshot ?? ['header' => [], 'rows' => []];
@endphp

<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
    <table class="w-full text-sm text-left">
        <thead class="bg-gray-50 dark:bg-white/5">
            <tr>
                @foreach ($snapshot['header'] as $headerCell)
                    <th class="whitespace-nowrap px-3 py-2 font-medium text-gray-700 dark:text-gray-200">
                        {{ $headerCell }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($snapshot['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-gray-500 dark:text-gray-400">
                            {{ $cell }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
