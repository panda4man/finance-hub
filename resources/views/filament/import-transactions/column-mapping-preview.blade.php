@if (empty($mapping))
    <span class="text-sm text-gray-500 dark:text-gray-400">—</span>
@else
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="whitespace-nowrap px-3 py-2 font-medium text-gray-700 dark:text-gray-200">Field</th>
                    <th class="whitespace-nowrap px-3 py-2 font-medium text-gray-700 dark:text-gray-200">CSV column</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($mapping as $label => $header)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-200">{{ $label }}</td>
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-gray-500 dark:text-gray-400">{{ $header }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
