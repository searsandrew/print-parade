<?php

use App\Labels\Enums\PrintJobStatus;
use App\Models\LabelTemplate;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Print jobs')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $template = '';

    #[Url]
    public string $printer = '';

    #[Url]
    public string $operator = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public ?string $selectedJobId = null;

    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    /** @return LengthAwarePaginator<int, PrintJob> */
    #[Computed]
    public function jobs(): LengthAwarePaginator
    {
        return PrintJob::query()
            ->with([
                'labelTemplateVersion.labelTemplate.labelStock',
                'printer.printBridge',
                'submitter',
                'executor',
                'claimingBridge',
            ])
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('id', 'like', "%{$search}%")
                        ->orWhereHas('labelTemplateVersion.labelTemplate', function (Builder $query) use ($search): void {
                            $query->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('printer', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('submitter', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('executor', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->template !== '', function (Builder $query): void {
                $query->whereHas('labelTemplateVersion', fn (Builder $query) => $query->where('label_template_id', $this->template));
            })
            ->when($this->printer !== '', fn (Builder $query) => $query->where('printer_id', $this->printer))
            ->when($this->operator !== '', fn (Builder $query) => $query->where('executed_by', $this->operator))
            ->when($this->dateFrom !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->latest()
            ->paginate(25);
    }

    /** @return Collection<int, LabelTemplate> */
    #[Computed]
    public function templates(): Collection
    {
        return LabelTemplate::query()->orderBy('code')->get();
    }

    /** @return Collection<int, Printer> */
    #[Computed]
    public function printers(): Collection
    {
        return Printer::query()->orderBy('name')->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function operators(): Collection
    {
        return User::query()
            ->whereHas('executedPrintJobs')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedJob(): ?PrintJob
    {
        if ($this->selectedJobId === null) {
            return null;
        }

        return PrintJob::query()
            ->with([
                'labelTemplateVersion.labelTemplate.labelStock',
                'printer.printBridge',
                'submitter',
                'executor',
                'claimingBridge',
            ])
            ->findOrFail($this->selectedJobId);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'template', 'printer', 'operator', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
            unset($this->jobs);
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'template', 'printer', 'operator', 'dateFrom', 'dateTo');
        $this->resetPage();
        unset($this->jobs);
    }

    public function viewJob(string $jobId): void
    {
        $this->selectedJobId = PrintJob::query()->findOrFail($jobId)->id;
        unset($this->selectedJob);
        Flux::modal('job-detail')->show();
    }

    public function cancelJob(string $jobId): void
    {
        $job = PrintJob::query()->findOrFail($jobId);
        $job->cancel();

        unset($this->jobs, $this->selectedJob);
        Flux::toast(variant: 'success', text: __('Print job cancelled.'));
    }

    public function statusLabel(PrintJobStatus $status): string
    {
        return match ($status) {
            PrintJobStatus::Pending => __('Pending'),
            PrintJobStatus::Queued => __('Queued'),
            PrintJobStatus::Processing => __('Processing'),
            PrintJobStatus::DeliveryUncertain => __('Delivery uncertain'),
            PrintJobStatus::Completed => __('Completed'),
            PrintJobStatus::Failed => __('Failed'),
            PrintJobStatus::Cancelled => __('Cancelled'),
        };
    }

    public function statusColor(PrintJobStatus $status): string
    {
        return match ($status) {
            PrintJobStatus::Pending => 'zinc',
            PrintJobStatus::Queued => 'blue',
            PrintJobStatus::Processing => 'amber',
            PrintJobStatus::DeliveryUncertain => 'red',
            PrintJobStatus::Completed => 'green',
            PrintJobStatus::Failed => 'red',
            PrintJobStatus::Cancelled => 'zinc',
        };
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-7">
    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('admin.dashboard')" wire:navigate>{{ __('Administration') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Print jobs') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" class="mt-4">{{ __('Print jobs') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Search the immutable print audit trail and investigate job delivery status.') }}</flux:text>
    </div>

    <flux:card class="space-y-5">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" :label="__('Search')" placeholder="Job ID, template, printer, or person" />
            <flux:select wire:model.live="status" :label="__('Status')">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (PrintJobStatus::cases() as $jobStatus)
                    <flux:select.option :value="$jobStatus->value">{{ $this->statusLabel($jobStatus) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="template" :label="__('Template')">
                <flux:select.option value="">{{ __('All templates') }}</flux:select.option>
                @foreach ($this->templates as $labelTemplate)
                    <flux:select.option :value="$labelTemplate->id">{{ $labelTemplate->code }} · {{ $labelTemplate->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="printer" :label="__('Printer')">
                <flux:select.option value="">{{ __('All printers') }}</flux:select.option>
                @foreach ($this->printers as $filterPrinter)
                    <flux:select.option :value="$filterPrinter->id">{{ $filterPrinter->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="operator" :label="__('Operator')">
                <flux:select.option value="">{{ __('All operators') }}</flux:select.option>
                @foreach ($this->operators as $filterOperator)
                    <flux:select.option :value="$filterOperator->id">{{ $filterOperator->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live="dateFrom" type="date" :label="__('From date')" />
            <flux:input wire:model.live="dateTo" type="date" :label="__('Through date')" />
            <div class="flex items-end">
                <flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            </div>
        </div>
    </flux:card>

    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Job') }}</flux:table.column>
                    <flux:table.column>{{ __('Template') }}</flux:table.column>
                    <flux:table.column>{{ __('Printer') }}</flux:table.column>
                    <flux:table.column>{{ __('Operator') }}</flux:table.column>
                    <flux:table.column>{{ __('Quantity') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Requested') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->jobs as $job)
                        <flux:table.row :key="$job->id">
                            <flux:table.cell>
                                <button type="button" class="font-mono font-medium text-blue-600 hover:underline dark:text-blue-400" wire:click="viewJob('{{ $job->id }}')">{{ $job->shortIdentifier() }}</button>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium">{{ $job->labelTemplateVersion->labelTemplate->code }}</div>
                                <div class="text-sm text-zinc-500">{{ $job->labelTemplateVersion->revision_code }} · v{{ $job->labelTemplateVersion->version }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div>{{ $job->printer->name }}</div>
                                <div class="text-sm text-zinc-500">{{ $job->printer->location }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div>{{ $job->executor?->name ?? __('Not authorized') }}</div>
                                @if ($job->submitter && ! $job->submitter->is($job->executor))
                                    <div class="text-sm text-zinc-500">{{ __('Submitted by :name', ['name' => $job->submitter->name]) }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ number_format($job->quantity) }}</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$this->statusColor($job->status)" size="sm">{{ $this->statusLabel($job->status) }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $job->created_at?->format('M j, Y g:i A') }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" icon="eye" wire:click="viewJob('{{ $job->id }}')">{{ __('Details') }}</flux:button>
                                    @if (in_array($job->status, [PrintJobStatus::Pending, PrintJobStatus::Queued], true))
                                        <flux:button size="sm" variant="danger" wire:click="cancelJob('{{ $job->id }}')" wire:confirm="{{ __('Cancel this print job? It will not be offered to a bridge.') }}">{{ __('Cancel') }}</flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="8"><div class="py-10 text-center text-zinc-500">{{ __('No print jobs match these filters.') }}</div></flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($this->jobs->hasPages())
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">{{ $this->jobs->links() }}</div>
        @endif
    </flux:card>

    <flux:modal name="job-detail" class="md:w-4xl">
        @if ($this->selectedJob)
            @php($job = $this->selectedJob)
            <div class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">{{ __('Print job :id', ['id' => $job->shortIdentifier()]) }}</flux:heading>
                        <flux:text class="mt-1 font-mono text-xs">{{ $job->id }}</flux:text>
                    </div>
                    <flux:badge :color="$this->statusColor($job->status)">{{ $this->statusLabel($job->status) }}</flux:badge>
                </div>

                @if ($job->status === PrintJobStatus::DeliveryUncertain)
                    <flux:callout variant="danger" icon="exclamation-triangle" heading="{{ __('Delivery is uncertain') }}">
                        {{ __('The bridge claimed this job but did not acknowledge it before the lease expired. Do not reprint until an operator confirms whether labels were produced.') }}
                    </flux:callout>
                @elseif ($job->failure_message)
                    <flux:callout variant="danger" icon="x-circle" heading="{{ __('Job failed') }}">{{ $job->failure_message }}</flux:callout>
                @endif

                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div><flux:text class="text-sm">{{ __('Template') }}</flux:text><div class="mt-1 font-medium">{{ $job->labelTemplateVersion->labelTemplate->code }} ({{ $job->labelTemplateVersion->revision_code }}) · v{{ $job->labelTemplateVersion->version }}</div></div>
                    <div><flux:text class="text-sm">{{ __('Stock') }}</flux:text><div class="mt-1 font-medium">{{ $job->labelTemplateVersion->labelTemplate->labelStock->name }}</div></div>
                    <div><flux:text class="text-sm">{{ __('Quantity') }}</flux:text><div class="mt-1 font-medium">{{ number_format($job->quantity) }}</div></div>
                    <div><flux:text class="text-sm">{{ __('Printer') }}</flux:text><div class="mt-1 font-medium">{{ $job->printer->name }}</div><div class="text-sm text-zinc-500">{{ $job->printer->location }}</div></div>
                    <div><flux:text class="text-sm">{{ __('Configured bridge') }}</flux:text><div class="mt-1 font-medium">{{ $job->printer->printBridge->name }}</div></div>
                    <div><flux:text class="text-sm">{{ __('Claiming bridge') }}</flux:text><div class="mt-1 font-medium">{{ $job->claimingBridge?->name ?? __('Not claimed') }}</div></div>
                    <div><flux:text class="text-sm">{{ __('Submitted by') }}</flux:text><div class="mt-1 font-medium">{{ $job->submitter?->name ?? __('Unknown') }}</div></div>
                    <div><flux:text class="text-sm">{{ __('Executed by') }}</flux:text><div class="mt-1 font-medium">{{ $job->executor?->name ?? __('Not authorized') }}</div></div>
                    <div><flux:text class="text-sm">{{ __('Payload') }}</flux:text><div class="mt-1 font-medium">{{ $job->output_payload === null ? __('Not rendered') : trans_choice('{1} :count byte|[2,*] :count bytes', strlen($job->output_payload), ['count' => strlen($job->output_payload)]) }}</div></div>
                </div>

                <flux:separator />

                <div>
                    <flux:heading size="sm">{{ __('Lifecycle') }}</flux:heading>
                    <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            __('Requested') => $job->created_at,
                            __('Queued') => $job->queued_at,
                            __('Claimed') => $job->claimed_at,
                            __('Lease expires') => $job->lease_expires_at,
                            __('Delivery uncertain') => $job->delivery_uncertain_at,
                            __('Completed') => $job->completed_at,
                            __('Failed') => $job->failed_at,
                            __('Cancelled') => $job->cancelled_at,
                        ] as $label => $timestamp)
                            <div><span class="text-zinc-500">{{ $label }}</span><div class="mt-1">{{ $timestamp?->format('M j, Y g:i:s A') ?? '—' }}</div></div>
                        @endforeach
                    </div>
                </div>

                <flux:separator />

                <div class="grid gap-5 lg:grid-cols-2">
                    <div>
                        <flux:heading size="sm">{{ __('Input values') }}</flux:heading>
                        <pre class="mt-3 max-h-72 overflow-auto rounded-lg bg-zinc-950 p-4 text-xs text-zinc-100">{{ json_encode($job->input_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                    <div>
                        <flux:heading size="sm">{{ __('Payload integrity') }}</flux:heading>
                        <dl class="mt-3 space-y-3 text-sm">
                            <div><dt class="text-zinc-500">{{ __('SHA-256 checksum') }}</dt><dd class="mt-1 break-all font-mono">{{ $job->output_checksum ?? '—' }}</dd></div>
                            <div><dt class="text-zinc-500">{{ __('Lease state') }}</dt><dd class="mt-1">{{ $job->lease_expires_at && $job->lease_expires_at->isPast() ? __('Expired') : ($job->lease_expires_at ? __('Active') : __('Not claimed')) }}</dd></div>
                        </dl>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    @if (in_array($job->status, [PrintJobStatus::Pending, PrintJobStatus::Queued], true))
                        <flux:button variant="danger" wire:click="cancelJob('{{ $job->id }}')" wire:confirm="{{ __('Cancel this print job?') }}">{{ __('Cancel job') }}</flux:button>
                    @endif
                    <flux:modal.close><flux:button variant="primary">{{ __('Close') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
