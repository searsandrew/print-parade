<?php

use App\Labels\Enums\PrinterLanguage;
use App\Models\PrintBridge;
use App\Models\Printer;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bridges & printers')] class extends Component {
    public ?int $bridgeId = null;

    public string $bridgeName = '';

    public bool $bridgeIsActive = true;

    public ?int $printerId = null;

    public int|string|null $printerBridgeId = null;

    public string $printerName = '';

    public string $printerLocation = '';

    public string $printerLanguage = 'zpl';

    public int|string $printerDpi = 203;

    public string $printerIdentifier = '';

    public bool $printerIsActive = true;

    public string $issuedToken = '';

    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    /** @return Collection<int, PrintBridge> */
    #[Computed]
    public function bridges(): Collection
    {
        return PrintBridge::query()
            ->with(['printers' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();
    }

    public function createBridge(): void
    {
        $this->resetBridgeForm();
        Flux::modal('bridge-form')->show();
    }

    public function editBridge(int $bridgeId): void
    {
        $bridge = PrintBridge::query()->findOrFail($bridgeId);

        $this->bridgeId = $bridge->id;
        $this->bridgeName = $bridge->name;
        $this->bridgeIsActive = $bridge->is_active;
        $this->resetValidation();

        Flux::modal('bridge-form')->show();
    }

    public function saveBridge(): void
    {
        $validated = $this->validate([
            'bridgeName' => ['required', 'string', 'max:255'],
            'bridgeIsActive' => ['required', 'boolean'],
        ]);

        $bridge = $this->bridgeId === null
            ? new PrintBridge()
            : PrintBridge::query()->findOrFail($this->bridgeId);

        $bridge->fill([
            'name' => $validated['bridgeName'],
            'is_active' => $validated['bridgeIsActive'],
        ])->save();

        $isNewBridge = $this->bridgeId === null;

        if ($isNewBridge) {
            $this->issuedToken = $bridge->issueToken();
        }

        $this->resetBridgeForm();
        unset($this->bridges);
        Flux::modal('bridge-form')->close();

        if ($isNewBridge) {
            Flux::modal('bridge-token')->show();
        } else {
            Flux::toast(variant: 'success', text: __('Print bridge updated.'));
        }
    }

    public function rotateBridgeToken(int $bridgeId): void
    {
        $bridge = PrintBridge::query()->findOrFail($bridgeId);
        $this->issuedToken = $bridge->issueToken();

        Flux::modal('bridge-token')->show();
    }

    public function dismissIssuedToken(): void
    {
        $this->issuedToken = '';
        Flux::modal('bridge-token')->close();
    }

    public function createPrinter(int $bridgeId): void
    {
        PrintBridge::query()->findOrFail($bridgeId);

        $this->resetPrinterForm();
        $this->printerBridgeId = $bridgeId;
        Flux::modal('printer-form')->show();
    }

    public function editPrinter(int $printerId): void
    {
        $printer = Printer::query()->findOrFail($printerId);

        $this->printerId = $printer->id;
        $this->printerBridgeId = $printer->print_bridge_id;
        $this->printerName = $printer->name;
        $this->printerLocation = $printer->location ?? '';
        $this->printerLanguage = $printer->language->value;
        $this->printerDpi = $printer->dpi;
        $this->printerIdentifier = $printer->bridge_identifier;
        $this->printerIsActive = $printer->is_active;
        $this->resetValidation();

        Flux::modal('printer-form')->show();
    }

    public function savePrinter(): void
    {
        $validated = $this->validate([
            'printerBridgeId' => ['required', 'integer', 'exists:print_bridges,id'],
            'printerName' => ['required', 'string', 'max:255'],
            'printerLocation' => ['nullable', 'string', 'max:255'],
            'printerLanguage' => ['required', Rule::enum(PrinterLanguage::class)],
            'printerDpi' => ['required', 'integer', Rule::in([203, 300])],
            'printerIdentifier' => [
                'required',
                'string',
                'max:255',
                Rule::unique('printers', 'bridge_identifier')
                    ->where('print_bridge_id', $this->printerBridgeId)
                    ->ignore($this->printerId),
            ],
            'printerIsActive' => ['required', 'boolean'],
        ]);

        $printer = $this->printerId === null
            ? new Printer()
            : Printer::query()->findOrFail($this->printerId);

        $printer->fill([
            'print_bridge_id' => $validated['printerBridgeId'],
            'name' => $validated['printerName'],
            'location' => filled($validated['printerLocation']) ? $validated['printerLocation'] : null,
            'language' => $validated['printerLanguage'],
            'dpi' => $validated['printerDpi'],
            'bridge_identifier' => $validated['printerIdentifier'],
            'is_active' => $validated['printerIsActive'],
        ])->save();

        $this->resetPrinterForm();
        unset($this->bridges);
        Flux::modal('printer-form')->close();
        Flux::toast(variant: 'success', text: __('Printer saved.'));
    }

    private function resetBridgeForm(): void
    {
        $this->reset('bridgeId', 'bridgeName');
        $this->bridgeIsActive = true;
        $this->resetValidation();
    }

    private function resetPrinterForm(): void
    {
        $this->reset('printerId', 'printerBridgeId', 'printerName', 'printerLocation', 'printerIdentifier');
        $this->printerLanguage = PrinterLanguage::Zpl->value;
        $this->printerDpi = 203;
        $this->printerIsActive = true;
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.dashboard')" wire:navigate>{{ __('Administration') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Bridges & printers') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" class="mt-4">{{ __('Bridges & printers') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Configure the Windows bridge computers and the printers each bridge can reach.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="createBridge">{{ __('Add bridge') }}</flux:button>
    </div>

    @forelse ($this->bridges as $bridge)
        @php($isOnline = $bridge->last_seen_at?->greaterThanOrEqualTo(now()->subMinutes(2)) ?? false)
        <flux:card class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">{{ $bridge->name }}</flux:heading>
                        <flux:badge :color="$bridge->is_active ? 'green' : 'zinc'" size="sm">
                            {{ $bridge->is_active ? __('Active') : __('Disabled') }}
                        </flux:badge>
                        <flux:badge :color="$isOnline ? 'green' : 'amber'" size="sm">
                            {{ $isOnline ? __('Online') : __('Offline') }}
                        </flux:badge>
                    </div>
                    <flux:text class="mt-1">
                        {{ $bridge->last_seen_at ? __('Last check-in :time', ['time' => $bridge->last_seen_at->diffForHumans()]) : __('This bridge has never checked in.') }}
                    </flux:text>
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" variant="ghost" icon="key" wire:click="rotateBridgeToken({{ $bridge->id }})" wire:confirm="{{ __('Rotate this bridge token? The current token will stop working immediately.') }}">
                        {{ __('Rotate token') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editBridge({{ $bridge->id }})">{{ __('Edit') }}</flux:button>
                    <flux:button size="sm" icon="plus" wire:click="createPrinter({{ $bridge->id }})">{{ __('Add printer') }}</flux:button>
                </div>
            </div>

            @if ($bridge->printers->isEmpty())
                <flux:callout icon="printer">{{ __('No printers are configured for this bridge yet.') }}</flux:callout>
            @else
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Printer') }}</flux:table.column>
                            <flux:table.column>{{ __('Language') }}</flux:table.column>
                            <flux:table.column>{{ __('Resolution') }}</flux:table.column>
                            <flux:table.column>{{ __('Bridge identifier') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($bridge->printers as $printer)
                                <flux:table.row :key="$printer->id">
                                    <flux:table.cell>
                                        <div class="font-medium">{{ $printer->name }}</div>
                                        <div class="text-sm text-zinc-500">{{ $printer->location ?: __('No location') }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ strtoupper($printer->language->value) }}</flux:table.cell>
                                    <flux:table.cell>{{ __(':dpi DPI', ['dpi' => $printer->dpi]) }}</flux:table.cell>
                                    <flux:table.cell><code class="text-sm">{{ $printer->bridge_identifier }}</code></flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge :color="$printer->is_active ? 'green' : 'zinc'" size="sm">
                                            {{ $printer->is_active ? __('Active') : __('Disabled') }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-end">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editPrinter({{ $printer->id }})">{{ __('Edit') }}</flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif
        </flux:card>
    @empty
        <flux:callout icon="computer-desktop" heading="{{ __('No print bridges configured') }}">
            {{ __('Add the production-room computer first. You can then assign its printers and copy the token into the bridge application.') }}
        </flux:callout>
    @endforelse

    <flux:modal name="bridge-form" class="md:w-lg">
        <form wire:submit="saveBridge" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $bridgeId ? __('Edit print bridge') : __('Add print bridge') }}</flux:heading>
                <flux:text class="mt-2">{{ __('A bridge represents one computer running the local Print Parade bridge application.') }}</flux:text>
            </div>
            <flux:input wire:model="bridgeName" :label="__('Name')" placeholder="Production Room PC" required />
            <flux:switch wire:model="bridgeIsActive" :label="__('Bridge is active')" :description="__('Disabled bridges cannot authenticate or claim print jobs.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save bridge') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="printer-form" class="md:w-xl">
        <form wire:submit="savePrinter" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $printerId ? __('Edit printer') : __('Add printer') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The bridge identifier must match the printer name used by the Windows bridge.') }}</flux:text>
            </div>
            <flux:select wire:model="printerBridgeId" :label="__('Print bridge')" required>
                @foreach ($this->bridges as $bridge)
                    <flux:select.option :value="$bridge->id">{{ $bridge->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="printerName" :label="__('Name')" placeholder="Packing Zebra ZT411" required />
                <flux:input wire:model="printerLocation" :label="__('Location')" placeholder="Packing Station 1" />
                <flux:select wire:model="printerLanguage" :label="__('Printer language')" required>
                    @foreach (PrinterLanguage::cases() as $language)
                        <flux:select.option :value="$language->value">{{ strtoupper($language->value) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="printerDpi" :label="__('Resolution')" required>
                    <flux:select.option value="203">{{ __('203 DPI') }}</flux:select.option>
                    <flux:select.option value="300">{{ __('300 DPI') }}</flux:select.option>
                </flux:select>
            </div>
            <flux:input wire:model="printerIdentifier" :label="__('Bridge identifier')" placeholder="packing-zebra-01" required />
            <flux:switch wire:model="printerIsActive" :label="__('Printer is active')" :description="__('Disabled printers are hidden from the print station and receive no new jobs.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save printer') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="bridge-token" class="md:w-xl" :dismissible="false">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Copy the bridge token now') }}</flux:heading>
                <flux:text class="mt-2">{{ __('For security, Print Parade stores only a hash and cannot display this token again. Rotating it will invalidate the previous token.') }}</flux:text>
            </div>
            <flux:input :value="$issuedToken" readonly copyable :label="__('Bridge token')" />
            <div class="flex justify-end">
                <flux:button variant="primary" wire:click="dismissIssuedToken">{{ __('I have saved the token') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
