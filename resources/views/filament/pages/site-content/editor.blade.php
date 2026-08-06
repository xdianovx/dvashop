<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($this->canUpdate())
            <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">
                Сохранить изменения
            </x-filament::button>
        @endif
    </form>
</x-filament-panels::page>
