<x-layouts::app :title="__('Administration')">
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8">
        <div>
            <flux:heading size="xl">{{ __('Administration') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Manage the equipment, labels, jobs, and people that keep Print Parade running.') }}</flux:text>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['icon' => 'printer', 'title' => __('Bridges & printers'), 'description' => __('Configure print bridges and the printers connected to them.'), 'status' => __('Available'), 'href' => route('admin.printers')],
                ['icon' => 'rectangle-stack', 'title' => __('Label stocks'), 'description' => __('Manage physical label dimensions and media sensing.'), 'status' => __('Available'), 'href' => route('admin.label-stocks')],
                ['icon' => 'document-text', 'title' => __('Templates & revisions'), 'description' => __('Create labels and publish immutable printer-neutral revisions.'), 'status' => __('Available'), 'href' => route('admin.label-templates')],
                ['icon' => 'queue-list', 'title' => __('Print jobs'), 'description' => __('Inspect job history, status, attribution, and delivery failures.'), 'status' => __('Available'), 'href' => route('admin.print-jobs')],
                ['icon' => 'users', 'title' => __('Users'), 'description' => __('Manage login accounts, shared stations, and administrator access.'), 'status' => __('Available'), 'href' => route('admin.users')],
                ['icon' => 'identification', 'title' => __('Employees'), 'description' => __('Manage production operators and their print PINs.'), 'status' => __('Available'), 'href' => route('admin.employees')],
            ] as $area)
                <flux:card class="flex min-h-48 flex-col gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <flux:icon :name="$area['icon']" class="size-7 text-zinc-500 dark:text-zinc-400" />
                        <flux:badge :color="$area['status'] === __('Available') ? 'green' : 'zinc'" size="sm">{{ $area['status'] }}</flux:badge>
                    </div>

                    <div>
                        <flux:heading size="lg">{{ $area['title'] }}</flux:heading>
                        <flux:text class="mt-2">{{ $area['description'] }}</flux:text>
                    </div>

                    @if (isset($area['href']))
                        <div class="mt-auto">
                            <flux:button :href="$area['href']" variant="primary" size="sm" wire:navigate>{{ __('Manage') }}</flux:button>
                        </div>
                    @endif
                </flux:card>
            @endforeach
        </div>
    </div>
</x-layouts::app>
