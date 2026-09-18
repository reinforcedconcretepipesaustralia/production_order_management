@php
    use Filament\Support\Icons\Heroicon;
@endphp

<button
    type="button"
    x-data="{}"
    aria-controls="fi-main-sidebar"
    x-bind:aria-expanded="$store.sidebar.isOpen"
    x-bind:aria-label="$store.sidebar.isOpen ? @js(__('filament-panels::layout.actions.sidebar.collapse.label')) : @js(__('filament-panels::layout.actions.sidebar.expand.label'))"
    x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
    class="rcpa-sidebar-toggle"
>
    {{ \Filament\Support\generate_icon_html(Heroicon::OutlinedChevronDoubleLeft) }}

    <span
        x-show="$store.sidebar.isOpen"
        class="rcpa-sidebar-toggle-label"
    >
        {{ __('Collapse') }}
    </span>
</button>
