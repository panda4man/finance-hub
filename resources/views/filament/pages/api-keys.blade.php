<x-filament-panels::page>
    @if ($this->plainTextToken)
        <x-filament::section
            icon="heroicon-o-key"
            icon-color="warning"
            heading="Copy your API key now"
            description="It will not be shown again."
        >
            <div class="flex items-center gap-3">
                <code
                    class="flex-1 overflow-x-auto rounded-lg bg-gray-950/5 px-3 py-2 font-mono text-sm dark:bg-white/5"
                >{{ $this->plainTextToken }}</code>

                <x-filament::button
                    x-on:click="window.navigator.clipboard.writeText(@js($this->plainTextToken))"
                    color="gray"
                    icon="heroicon-o-clipboard"
                    size="sm"
                >
                    Copy
                </x-filament::button>
            </div>

            <x-slot name="footer">
                <x-filament::button wire:click="dismissPlainTextToken" color="gray" size="sm">
                    I've copied it
                </x-filament::button>
            </x-slot>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
