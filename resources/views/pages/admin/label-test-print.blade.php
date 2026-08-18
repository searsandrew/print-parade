<?php

use App\Labels\Printing\LabelTestPreviewer;
use App\Labels\Printing\PrintJobSubmitter;
use App\Models\LabelTemplate;
use App\Models\Printer;
use App\Models\PrintJob;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Test print label')] class extends Component {
    public int $templateId;

    public int|string|null $printerId = null;

    /** @var array<string, mixed> */
    public array $values = [];

    public string $previewSvg = '';

    /** @var array<string, mixed> */
    public array $resolvedValues = [];

    public ?string $queuedJobId = null;

    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    public function mount(LabelTemplate $labelTemplate): void
    {
        $labelTemplate->load(['labelStock', 'publishedVersion']);
        abort_if($labelTemplate->publishedVersion === null, 404);

        $this->templateId = $labelTemplate->id;

        foreach ($labelTemplate->publishedVersion->definition->toArray()['fields'] as $name => $field) {
            $this->values[$name] = $field['default'] ?? match ($field['type']) {
                'boolean' => false,
                default => '',
            };
        }
    }

    #[Computed]
    public function template(): LabelTemplate
    {
        return LabelTemplate::query()
            ->with(['labelStock', 'publishedVersion'])
            ->findOrFail($this->templateId);
    }

    /** @return Collection<int, Printer> */
    #[Computed]
    public function printers(): Collection
    {
        return Printer::query()
            ->where('label_stock_id', $this->template->label_stock_id)
            ->where('is_active', true)
            ->whereHas('printBridge', fn ($query) => $query->where('is_active', true))
            ->with('printBridge')
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, array<string, mixed>> */
    #[Computed]
    public function fields(): array
    {
        return $this->template->publishedVersion->definition->toArray()['fields'];
    }

    /** @return array<string, scalar|null> */
    #[Computed]
    public function flattenedResolvedValues(): array
    {
        return Arr::dot($this->resolvedValues);
    }

    public function updatedValues(): void
    {
        $this->clearPreview();
    }

    public function resolvePreview(LabelTestPreviewer $previewer): void
    {
        $this->resetErrorBag('testPrint');
        $this->queuedJobId = null;

        try {
            $preview = $previewer->preview($this->template, $this->values);
            $this->previewSvg = $preview->svg;
            $this->resolvedValues = $preview->resolvedValues;
        } catch (\InvalidArgumentException|\LogicException $exception) {
            $this->clearPreview();
            $this->addError('testPrint', $exception->getMessage());
        }
    }

    public function queueTestPrint(PrintJobSubmitter $submitter): void
    {
        $this->resetErrorBag('testPrint');

        if ($this->previewSvg === '') {
            $this->addError('testPrint', __('Resolve and inspect the preview before sending a test print.'));

            return;
        }

        $validated = $this->validate([
            'printerId' => ['required', 'integer', 'exists:printers,id'],
        ]);

        try {
            /** @var PrintJob $job */
            $job = $submitter->submit(
                $this->template,
                Printer::query()->findOrFail($validated['printerId']),
                Auth::user(),
                null,
                null,
                1,
                $this->values,
            );
            $this->queuedJobId = $job->id;
            Flux::toast(variant: 'success', text: __('Quantity-one test print queued.'));
        } catch (\InvalidArgumentException|\LogicException $exception) {
            $this->addError('testPrint', $exception->getMessage());
        }
    }

    private function clearPreview(): void
    {
        $this->previewSvg = '';
        $this->resolvedValues = [];
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.label-templates')" wire:navigate>{{ __('Templates & revisions') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $this->template->code }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Test print') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" class="mt-4">{{ __('Test print') }} · {{ $this->template->code }}</flux:heading>
            <flux:text class="mt-2">
                {{ $this->template->name }} · {{ __('Revision :revision', ['revision' => $this->template->publishedVersion->revision_code]) }}
            </flux:text>
        </div>

        <flux:badge color="amber" size="lg">{{ __('Administrative test · quantity 1') }}</flux:badge>
    </div>

    @error('testPrint')
        <flux:callout variant="danger" icon="exclamation-triangle" heading="{{ __('Unable to prepare test print') }}">{{ $message }}</flux:callout>
    @enderror

    @if ($queuedJobId)
        <flux:callout variant="success" icon="check-circle" heading="{{ __('Test print queued') }}">
            {{ __('Job :job is waiting for its printer bridge.', ['job' => strtoupper(substr($queuedJobId, -8))]) }}
            <x-slot name="actions">
                <flux:button :href="route('admin.print-jobs')" wire:navigate>{{ __('View print jobs') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Test values') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Enter only operator-supplied fields. Namespaced values will be retrieved from their integrations.') }}</flux:text>
            </div>

            @foreach ($this->fields as $name => $field)
                <div wire:key="test-field-{{ $name }}">
                    @if ($field['type'] === 'boolean')
                        <flux:switch wire:model.live="values.{{ $name }}" :label="$field['label']" />
                    @else
                        <flux:input
                            wire:model.live.debounce.400ms="values.{{ $name }}"
                            :type="match ($field['type']) { 'number' => 'number', 'date' => 'date', default => 'text' }"
                            :label="$field['label']"
                            :description="'{{ '.$name.' }}'"
                            :required="$field['required']"
                        />
                    @endif
                </div>
            @endforeach

            <flux:select wire:model="printerId" :label="__('Compatible printer')" required>
                <flux:select.option value="">{{ __('Select a printer') }}</flux:select.option>
                @foreach ($this->printers as $printer)
                    @php($online = $printer->printBridge->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false)
                    <flux:select.option wire:key="test-printer-{{ $printer->id }}" :value="$printer->id" :disabled="! $online">
                        {{ $printer->name }}{{ $printer->location ? ' · '.$printer->location : '' }}{{ $online ? '' : ' · Offline' }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->printers->isEmpty())
                <flux:callout variant="warning" icon="printer">{{ __('No active printer has this template’s stock loaded.') }}</flux:callout>
            @endif

            <div class="flex flex-wrap justify-end gap-3">
                <flux:button icon="arrow-path" wire:click="resolvePreview">{{ __('Resolve & preview') }}</flux:button>
                <flux:button variant="primary" icon="printer" wire:click="queueTestPrint" :disabled="$previewSvg === '' || $printerId === null">
                    {{ __('Send test print') }}
                </flux:button>
            </div>
        </flux:card>

        <div class="space-y-6">
            <flux:card class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Resolved preview') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Datasource values are fetched live. Review them before sending the job.') }}</flux:text>
                </div>

                @if ($previewSvg !== '')
                    <div class="flex min-h-80 items-center justify-center overflow-auto rounded-lg bg-zinc-100 p-6 dark:bg-zinc-900 [&>svg]:max-h-[60vh] [&>svg]:max-w-full">
                        {!! $previewSvg !!}
                    </div>
                @else
                    <div class="flex min-h-80 items-center justify-center rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                        <flux:text>{{ __('Enter the operator values and resolve the preview.') }}</flux:text>
                    </div>
                @endif
            </flux:card>

            @if ($this->flattenedResolvedValues !== [])
                <flux:card class="space-y-4">
                    <flux:heading size="lg">{{ __('Resolved values') }}</flux:heading>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Field') }}</flux:table.column>
                            <flux:table.column>{{ __('Value') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->flattenedResolvedValues as $name => $value)
                                <flux:table.row wire:key="resolved-value-{{ $name }}">
                                    <flux:table.cell><code>{{ $name }}</code></flux:table.cell>
                                    <flux:table.cell class="break-all">{{ is_bool($value) ? ($value ? __('Yes') : __('No')) : ($value ?? __('Empty')) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endif
        </div>
    </div>
</div>
