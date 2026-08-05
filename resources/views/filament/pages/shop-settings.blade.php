<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($this->canUpdate())
            <x-filament::button type="submit" wire:loading.attr="disabled">
                Сохранить настройки
            </x-filament::button>
        @endif
    </form>
</x-filament-panels::page>
