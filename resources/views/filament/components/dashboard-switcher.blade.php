<header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <h1 class="fi-header-heading text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
        {{ $heading }}
    </h1>

    @if (count($dashboards))
        <x-filament::tabs>
            @foreach ($dashboards as $dashboard)
                <x-filament::tabs.item
                    :active="$dashboard['active']"
                    :href="$dashboard['url']"
                    :icon="$dashboard['icon']"
                    tag="a"
                >
                    {{ $dashboard['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    @endif
</header>
