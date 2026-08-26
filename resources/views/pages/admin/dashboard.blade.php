<?php

use App\Labels\Enums\PrintJobStatus;
use App\Models\Employee;
use App\Models\LabelStock;
use App\Models\LabelTemplate;
use App\Models\PrintBridge;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Administration')] class extends Component {
    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    /** @return array<int, array{icon: string, title: string, description: string, status: string, color: string, href: string}> */
    #[Computed]
    public function areas(): array
    {
        $onlineCutoff = now()->subMinutes(2);
        $activeBridges = PrintBridge::query()->where('is_active', true)->count();
        $onlineBridges = PrintBridge::query()
            ->where('is_active', true)
            ->where('last_seen_at', '>=', $onlineCutoff)
            ->count();
        $activePrinters = Printer::query()
            ->where('is_active', true)
            ->whereHas('printBridge', fn ($query) => $query->where('is_active', true))
            ->count();
        $onlinePrinters = Printer::query()
            ->where('is_active', true)
            ->whereHas('printBridge', fn ($query) => $query
                ->where('is_active', true)
                ->where('last_seen_at', '>=', $onlineCutoff))
            ->count();
        $offlinePrinters = $activePrinters - $onlinePrinters;

        $activeStocks = LabelStock::query()->where('is_active', true)->count();
        $totalStocks = LabelStock::query()->count();

        $activeTemplates = LabelTemplate::query()->where('is_active', true)->count();
        $publishedTemplates = LabelTemplate::query()
            ->where('is_active', true)
            ->whereHas('publishedVersion')
            ->count();
        $unpublishedTemplates = $activeTemplates - $publishedTemplates;

        $queuedJobs = PrintJob::query()->where('status', PrintJobStatus::Queued)->count();
        $processingJobs = PrintJob::query()->where('status', PrintJobStatus::Processing)->count();
        $uncertainJobs = PrintJob::query()->where('status', PrintJobStatus::DeliveryUncertain)->count();
        $spooledToday = PrintJob::query()
            ->where('status', PrintJobStatus::Spooled)
            ->whereDate('spooled_at', today())
            ->count();

        $users = User::query()->count();
        $administrators = User::query()->where('is_admin', true)->count();
        $sharedStations = User::query()->where('requires_print_operator_pin', true)->count();

        $activeEmployees = Employee::query()->where('is_active', true)->count();
        $readyEmployees = Employee::query()->where('is_active', true)->whereNotNull('pin_hash')->count();
        $employeesMissingPin = $activeEmployees - $readyEmployees;

        return [
            [
                'icon' => 'printer',
                'title' => __('Bridges & printers'),
                'description' => __(':online of :active active bridges connected · :printers configured printers', [
                    'online' => $onlineBridges,
                    'active' => $activeBridges,
                    'printers' => $activePrinters,
                ]),
                'status' => match (true) {
                    $activePrinters === 0 => __('No active printers'),
                    $offlinePrinters > 0 => trans_choice('{1} :count offline|[2,*] :count offline', $offlinePrinters, ['count' => $offlinePrinters]),
                    default => trans_choice('{1} :count online|[2,*] :count online', $onlinePrinters, ['count' => $onlinePrinters]),
                },
                'color' => match (true) {
                    $activePrinters === 0 => 'amber',
                    $offlinePrinters > 0 => 'red',
                    default => 'green',
                },
                'href' => route('admin.printers'),
            ],
            [
                'icon' => 'rectangle-stack',
                'title' => __('Label stocks'),
                'description' => __(':active active of :total configured stocks', ['active' => $activeStocks, 'total' => $totalStocks]),
                'status' => trans_choice('{0} No active stocks|{1} :count active|[2,*] :count active', $activeStocks, ['count' => $activeStocks]),
                'color' => $activeStocks > 0 ? 'blue' : 'amber',
                'href' => route('admin.label-stocks'),
            ],
            [
                'icon' => 'document-text',
                'title' => __('Templates & revisions'),
                'description' => __(':published published of :active active templates', ['published' => $publishedTemplates, 'active' => $activeTemplates]),
                'status' => $unpublishedTemplates > 0
                    ? trans_choice('{1} :count unpublished|[2,*] :count unpublished', $unpublishedTemplates, ['count' => $unpublishedTemplates])
                    : trans_choice('{0} No active templates|{1} :count published|[2,*] :count published', $publishedTemplates, ['count' => $publishedTemplates]),
                'color' => $unpublishedTemplates > 0 || $activeTemplates === 0 ? 'amber' : 'green',
                'href' => route('admin.label-templates'),
            ],
            [
                'icon' => 'queue-list',
                'title' => __('Print jobs'),
                'description' => __(':queued queued · :processing processing · :spooled sent today', [
                    'queued' => $queuedJobs,
                    'processing' => $processingJobs,
                    'spooled' => $spooledToday,
                ]),
                'status' => match (true) {
                    $uncertainJobs > 0 => trans_choice('{1} :count uncertain|[2,*] :count uncertain', $uncertainJobs, ['count' => $uncertainJobs]),
                    $queuedJobs + $processingJobs > 0 => trans_choice('{1} :count active|[2,*] :count active', $queuedJobs + $processingJobs, ['count' => $queuedJobs + $processingJobs]),
                    default => __('Queue clear'),
                },
                'color' => match (true) {
                    $uncertainJobs > 0 => 'red',
                    $queuedJobs + $processingJobs > 0 => 'blue',
                    default => 'green',
                },
                'href' => route('admin.print-jobs'),
            ],
            [
                'icon' => 'users',
                'title' => __('Users'),
                'description' => __(':admins administrators · :stations shared stations', ['admins' => $administrators, 'stations' => $sharedStations]),
                'status' => trans_choice('{0} No accounts|{1} :count account|[2,*] :count accounts', $users, ['count' => $users]),
                'color' => $users > 0 ? 'blue' : 'amber',
                'href' => route('admin.users'),
            ],
            [
                'icon' => 'identification',
                'title' => __('Employees'),
                'description' => __(':ready ready to print of :active active employees', ['ready' => $readyEmployees, 'active' => $activeEmployees]),
                'status' => $employeesMissingPin > 0
                    ? trans_choice('{1} :count missing PIN|[2,*] :count missing PINs', $employeesMissingPin, ['count' => $employeesMissingPin])
                    : trans_choice('{0} No active employees|{1} :count ready|[2,*] :count ready', $readyEmployees, ['count' => $readyEmployees]),
                'color' => $employeesMissingPin > 0 || $activeEmployees === 0 ? 'amber' : 'green',
                'href' => route('admin.employees'),
            ],
        ];
    }
}; ?>

<div wire:poll.15s.visible class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8">
    <div>
        <flux:heading size="xl">{{ __('Administration') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Live operational status and configuration for Print Parade.') }}</flux:text>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->areas as $area)
            <flux:card class="flex min-h-48 flex-col gap-4" wire:key="admin-area-{{ $loop->index }}">
                <div class="flex items-start justify-between gap-4">
                    <flux:icon :name="$area['icon']" class="size-7 text-zinc-500 dark:text-zinc-400" />
                    <flux:badge :color="$area['color']" size="sm">{{ $area['status'] }}</flux:badge>
                </div>

                <div>
                    <flux:heading size="lg">{{ $area['title'] }}</flux:heading>
                    <flux:text class="mt-2">{{ $area['description'] }}</flux:text>
                </div>

                <div class="mt-auto">
                    <flux:button :href="$area['href']" variant="primary" size="sm" wire:navigate>{{ __('Manage') }}</flux:button>
                </div>
            </flux:card>
        @endforeach
    </div>
</div>
