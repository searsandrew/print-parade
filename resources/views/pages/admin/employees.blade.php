<?php

use App\Models\Employee;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Employees')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public ?int $employeeId = null;

    public string $name = '';

    public string $employeeNumber = '';

    public bool $isActive = true;

    public string $pin = '';

    public string $pin_confirmation = '';

    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    /** @return LengthAwarePaginator<int, Employee> */
    #[Computed]
    public function employees(): LengthAwarePaginator
    {
        return Employee::query()
            ->withCount('operatedPrintJobs')
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_number', 'like', "%{$search}%");
                });
            })
            ->when($this->status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(25);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
            unset($this->employees);
        }
    }

    public function createEmployee(): void
    {
        $this->resetForm();
        Flux::modal('employee-form')->show();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
        unset($this->employees);
    }

    public function editEmployee(int $employeeId): void
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $this->employeeId = $employee->id;
        $this->name = $employee->name;
        $this->employeeNumber = $employee->employee_number ?? '';
        $this->isActive = $employee->is_active;
        $this->reset('pin', 'pin_confirmation');
        $this->resetValidation();
        Flux::modal('employee-form')->show();
    }

    public function saveEmployee(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'employeeNumber' => ['nullable', 'string', 'max:100', Rule::unique(Employee::class, 'employee_number')->ignore($this->employeeId)],
            'isActive' => ['required', 'boolean'],
            'pin' => [$this->employeeId === null ? 'required' : 'nullable', 'string', 'regex:/\A\d{4,8}\z/', 'confirmed'],
        ]);

        $employee = $this->employeeId === null
            ? new Employee()
            : Employee::query()->findOrFail($this->employeeId);
        $employee->fill([
            'name' => $validated['name'],
            'employee_number' => filled($validated['employeeNumber']) ? $validated['employeeNumber'] : null,
            'is_active' => $validated['isActive'],
        ]);

        if (filled($validated['pin'])) {
            $employee->assignPin($validated['pin']);
        }

        $employee->save();
        $this->resetForm();
        unset($this->employees);
        Flux::modal('employee-form')->close();
        Flux::toast(variant: 'success', text: __('Employee saved.'));
    }

    public function removePin(): void
    {
        $employee = Employee::query()->findOrFail($this->employeeId);
        $employee->removePin();
        $employee->save();
        $this->resetForm();
        unset($this->employees);
        Flux::modal('employee-form')->close();
        Flux::toast(variant: 'success', text: __('Employee PIN removed.'));
    }

    private function resetForm(): void
    {
        $this->reset('employeeId', 'name', 'employeeNumber', 'pin', 'pin_confirmation');
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-7">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs><flux:breadcrumbs.item :href="route('admin.dashboard')" wire:navigate>{{ __('Administration') }}</flux:breadcrumbs.item><flux:breadcrumbs.item>{{ __('Employees') }}</flux:breadcrumbs.item></flux:breadcrumbs>
            <flux:heading size="xl" class="mt-4">{{ __('Employees') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Manage the people who authorize production print jobs with a PIN. Employees do not log in to Print Parade.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="user-plus" wire:click="createEmployee">{{ __('Add employee') }}</flux:button>
    </div>

    <flux:card><div class="grid gap-4 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" :label="__('Search')" placeholder="Name or employee number" />
        <flux:select wire:model.live="status" :label="__('Status')"><flux:select.option value="">{{ __('All employees') }}</flux:select.option><flux:select.option value="active">{{ __('Active') }}</flux:select.option><flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option></flux:select>
        <div class="flex items-end"><flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button></div>
    </div></flux:card>

    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto"><flux:table>
            <flux:table.columns><flux:table.column>{{ __('Employee') }}</flux:table.column><flux:table.column>{{ __('PIN') }}</flux:table.column><flux:table.column>{{ __('Print history') }}</flux:table.column><flux:table.column>{{ __('Status') }}</flux:table.column><flux:table.column></flux:table.column></flux:table.columns>
            <flux:table.rows>
                @forelse ($this->employees as $employee)
                    <flux:table.row :key="$employee->id">
                        <flux:table.cell><div class="font-medium">{{ $employee->name }}</div><div class="text-sm text-zinc-500">{{ $employee->employee_number ?: __('No employee number') }}</div></flux:table.cell>
                        <flux:table.cell><flux:badge :color="$employee->pin_hash ? 'green' : 'red'" size="sm">{{ $employee->pin_hash ? __('Configured') : __('Missing') }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ trans_choice('{0} No print jobs|{1} :count print job|[2,*] :count print jobs', $employee->operated_print_jobs_count, ['count' => $employee->operated_print_jobs_count]) }}</flux:table.cell>
                        <flux:table.cell><flux:badge :color="$employee->is_active ? 'green' : 'zinc'" size="sm">{{ $employee->is_active ? __('Active') : __('Inactive') }}</flux:badge></flux:table.cell>
                        <flux:table.cell><div class="flex justify-end"><flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editEmployee({{ $employee->id }})">{{ __('Edit') }}</flux:button></div></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="5"><div class="py-10 text-center text-zinc-500">{{ __('No employees match these filters.') }}</div></flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table></div>
        @if ($this->employees->hasPages())<div class="border-t border-zinc-200 p-4 dark:border-zinc-700">{{ $this->employees->links() }}</div>@endif
    </flux:card>

    <flux:modal name="employee-form" class="md:w-xl">
        <form wire:submit="saveEmployee" class="space-y-6">
            <div><flux:heading size="lg">{{ $employeeId ? __('Edit employee') : __('Add employee') }}</flux:heading><flux:text class="mt-2">{{ __('PINs may be reused because operators select their name first. PINs are never displayed after saving.') }}</flux:text></div>
            <div class="grid gap-5 sm:grid-cols-2"><flux:input wire:model="name" :label="__('Name')" required /><flux:input wire:model="employeeNumber" :label="__('Employee number')" /><flux:input wire:model="pin" :label="$employeeId ? __('New PIN (optional)') : __('PIN')" type="password" inputmode="numeric" minlength="4" maxlength="8" autocomplete="new-password" viewable :required="$employeeId === null" /><flux:input wire:model="pin_confirmation" :label="__('Confirm PIN')" type="password" inputmode="numeric" minlength="4" maxlength="8" autocomplete="new-password" viewable :required="$employeeId === null" /></div>
            <flux:switch wire:model="isActive" :label="__('Active print operator')" :description="__('Only active employees with a PIN appear on shared stations.')" />
            <div class="flex flex-wrap justify-end gap-2">@if ($employeeId)<flux:button type="button" variant="danger" wire:click="removePin" wire:confirm="{{ __('Remove this employee PIN?') }}">{{ __('Remove PIN') }}</flux:button>@endif<flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" variant="primary">{{ __('Save employee') }}</flux:button></div>
        </form>
    </flux:modal>
</div>
