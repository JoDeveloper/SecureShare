<x-filament-panels::page>
    @if ($createdUrl)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('secure-share::messages.created') }}
            </x-slot>

            <x-slot name="description">
                {{ __('secure-share::messages.created_description') }}
            </x-slot>

            <div class="flex items-center gap-x-3"
                 x-data="{ copied: false }"
                 wire:ignore>
                <input type="text"
                       value="{{ $createdUrl }}"
                       readonly
                       x-ref="shareUrl"
                       @click="$refs.shareUrl.select()"
                       class="block w-full font-mono text-sm border-gray-300 rounded-lg shadow-sm dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200"
                >

                <x-filament::button color="gray"
                                    x-bind:disabled="copied"
                                    x-on:click="async () => {
                                        if (navigator.clipboard) {
                                            await navigator.clipboard.writeText('{{ $createdUrl }}');
                                            copied = true;
                                            setTimeout(() => copied = false, 2500);
                                        }
                                    }">
                    <span x-text="copied ? '{{ __('secure-share::messages.copied') }}' : '{{ __('secure-share::messages.copy') }}'">
                        {{ __('secure-share::messages.copy') }}
                    </span>
                </x-filament::button>
            </div>

            <p class="mt-4 text-sm text-danger-600 dark:text-danger-400">
                {{ __('secure-share::messages.separate_channel') }}
            </p>

            @if ($createdBurn)
                <p class="mt-1 text-sm text-warning-600 dark:text-warning-400">
                    {{ __('secure-share::messages.burn_notice') }}
                </p>
            @endif
        </x-filament::section>
    @endif

    <form wire:submit="create">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                {{ __('secure-share::messages.create') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
