<x-layouts::app :title="__('Administration')">
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8">
        <div>
            <flux:heading size="xl">{{ __('Administration') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Manage the equipment, labels, jobs, and people that keep Print Parade running.') }}</flux:text>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['icon' => 'printer', 'title' => __('Bridges & printers'), 'description' => __('Configure print bridges and the printers connected to them.'), 'status' => __('Next')],
                ['icon' => 'rectangle-stack', 'title' => __('Label stocks'), 'description' => __('Manage physical label dimensions and printable areas.'), 'status' => __('Planned')],
                ['icon' => 'document-text', 'title' => __('Templates & revisions'), 'description' => __('Design labels and publish immutable template revisions.'), 'status' => __('Planned')],
                ['icon' => 'queue-list', 'title' => __('Print jobs'), 'description' => __('Inspect job history, status, attribution, and delivery failures.'), 'status' => __('Planned')],
                ['icon' => 'users', 'title' => __('Users'), 'description' => __('Manage printing modes, operator PINs, and administrator access.'), 'status' => __('Planned')],
            ] as $area)
                <flux:card class="flex min-h-48 flex-col gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <flux:icon :name="$area['icon']" class="size-7 text-zinc-500 dark:text-zinc-400" />
                        <flux:badge :color="$area['status'] === __('Next') ? 'blue' : 'zinc'" size="sm">{{ $area['status'] }}</flux:badge>
                    </div>

                    <div>
                        <flux:heading size="lg">{{ $area['title'] }}</flux:heading>
                        <flux:text class="mt-2">{{ $area['description'] }}</flux:text>
                    </div>
                </flux:card>
            @endforeach
        </div>
    </div>
</x-layouts::app>
