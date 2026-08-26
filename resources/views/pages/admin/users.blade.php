<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $accountType = '';

    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $requiresPrintOperatorPin = false;

    public bool $isAdmin = false;

    public function boot(): void
    {
        abort_unless(Auth::user()?->is_admin, 403);
    }

    /** @return LengthAwarePaginator<int, User> */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->withCount(['executedPrintJobs', 'submittedPrintJobs'])
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($this->accountType === 'admin', fn (Builder $query) => $query->where('is_admin', true))
            ->when($this->accountType === 'personal', fn (Builder $query) => $query->where('requires_print_operator_pin', false))
            ->when($this->accountType === 'shared', fn (Builder $query) => $query->where('requires_print_operator_pin', true))
            ->orderBy('name')
            ->paginate(25);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'accountType'], true)) {
            $this->resetPage();
            unset($this->users);
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'accountType');
        $this->resetPage();
        unset($this->users);
    }

    public function createUser(): void
    {
        $this->resetUserForm();
        Flux::modal('user-form')->show();
    }

    public function editUser(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->requiresPrintOperatorPin = $user->requires_print_operator_pin;
        $this->isAdmin = $user->is_admin;
        $this->reset('password', 'password_confirmation');
        $this->resetValidation();

        Flux::modal('user-form')->show();
    }

    public function saveUser(): void
    {
        $passwordRules = $this->userId === null
            ? ['required', 'string', Password::default(), 'confirmed']
            : ['nullable', 'string', Password::default(), 'confirmed'];

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->userId)],
            'password' => $passwordRules,
            'requiresPrintOperatorPin' => ['required', 'boolean'],
            'isAdmin' => ['required', 'boolean'],
        ]);

        if ($this->userId !== null && $this->userId === Auth::id() && ! $validated['isAdmin']) {
            $this->addError('isAdmin', __('You cannot remove your own administrator access.'));

            return;
        }

        $saved = DB::transaction(function () use ($validated): bool {
            $user = $this->userId === null
                ? new User()
                : User::query()->lockForUpdate()->findOrFail($this->userId);

            if ($user->exists && $user->is_admin && ! $validated['isAdmin']) {
                $administratorIds = User::query()
                    ->where('is_admin', true)
                    ->lockForUpdate()
                    ->pluck('id');

                if ($administratorIds->count() <= 1) {
                    return false;
                }
            }

            $attributes = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'requires_print_operator_pin' => $validated['requiresPrintOperatorPin'],
                'is_admin' => $validated['isAdmin'],
            ];

            if ($user->email !== $validated['email'] || ! $user->exists) {
                $attributes['email_verified_at'] = now();
            }

            if (filled($validated['password'])) {
                $attributes['password'] = $validated['password'];
            }

            $user->forceFill($attributes)->save();

            return true;
        });

        if (! $saved) {
            $this->addError('isAdmin', __('The final administrator cannot be demoted.'));

            return;
        }

        $this->resetUserForm();
        unset($this->users);
        Flux::modal('user-form')->close();
        Flux::toast(variant: 'success', text: __('User saved.'));
    }

    private function resetUserForm(): void
    {
        $this->reset('userId', 'name', 'email', 'password', 'password_confirmation');
        $this->requiresPrintOperatorPin = false;
        $this->isAdmin = false;
        $this->resetValidation();
    }
}; ?>

<div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-7">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('admin.dashboard')" wire:navigate>{{ __('Administration') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Users') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" class="mt-4">{{ __('Users') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Manage login accounts, shared-station authorization, and administrator access. Employee PINs are managed separately.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="user-plus" wire:click="createUser">{{ __('Add user') }}</flux:button>
    </div>

    <flux:card>
        <div class="grid gap-4 md:grid-cols-3">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" :label="__('Search')" placeholder="Name or email" />
            <flux:select wire:model.live="accountType" :label="__('Account type')">
                <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
                <flux:select.option value="admin">{{ __('Administrators') }}</flux:select.option>
                <flux:select.option value="personal">{{ __('Personal accounts') }}</flux:select.option>
                <flux:select.option value="shared">{{ __('Shared stations') }}</flux:select.option>
            </flux:select>
            <div class="flex items-end"><flux:button variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button></div>
        </div>
    </flux:card>

    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('User') }}</flux:table.column>
                    <flux:table.column>{{ __('Printing mode') }}</flux:table.column>
                    <flux:table.column>{{ __('Print history') }}</flux:table.column>
                    <flux:table.column>{{ __('Security') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->users as $user)
                        <flux:table.row :key="$user->id">
                            <flux:table.cell>
                                <div class="flex items-center gap-3">
                                    <flux:avatar :name="$user->name" :initials="$user->initials()" size="sm" />
                                    <div><div class="font-medium">{{ $user->name }}</div><div class="text-sm text-zinc-500">{{ $user->email }}</div></div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$user->requires_print_operator_pin ? 'amber' : 'blue'" size="sm">
                                    {{ $user->requires_print_operator_pin ? __('Shared station') : __('Personal') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div>{{ trans_choice('{0} No jobs executed|{1} :count job executed|[2,*] :count jobs executed', $user->executed_print_jobs_count, ['count' => $user->executed_print_jobs_count]) }}</div>
                                <div class="text-sm text-zinc-500">{{ trans_choice('{0} No jobs submitted|{1} :count job submitted|[2,*] :count jobs submitted', $user->submitted_print_jobs_count, ['count' => $user->submitted_print_jobs_count]) }}</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-2">
                                    @if ($user->is_admin)<flux:badge color="purple" size="sm">{{ __('Administrator') }}</flux:badge>@endif
                                    @if ($user->two_factor_confirmed_at)<flux:badge color="green" size="sm">{{ __('2FA') }}</flux:badge>@endif
                                    @if ($user->email_verified_at)<flux:badge color="zinc" size="sm">{{ __('Verified') }}</flux:badge>@endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editUser({{ $user->id }})">{{ __('Edit') }}</flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="5"><div class="py-10 text-center text-zinc-500">{{ __('No users match these filters.') }}</div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
        @if ($this->users->hasPages())<div class="border-t border-zinc-200 p-4 dark:border-zinc-700">{{ $this->users->links() }}</div>@endif
    </flux:card>

    <flux:modal name="user-form" class="md:w-xl">
        <form wire:submit="saveUser" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $userId ? __('Edit user') : __('Add user') }}</flux:heading>
                <flux:text class="mt-2">{{ $userId ? __('Leave the password blank to keep the current password.') : __('The account is marked verified because it is being created by an administrator.') }}</flux:text>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" autocomplete="name" required />
                <flux:input wire:model="email" :label="__('Email')" type="email" autocomplete="email" required />
                <flux:input wire:model="password" :label="$userId ? __('New password') : __('Password')" type="password" autocomplete="new-password" viewable :required="$userId === null" />
                <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" autocomplete="new-password" viewable :required="$userId === null" />
            </div>
            <flux:switch wire:model="requiresPrintOperatorPin" :label="__('Require operator selection and PIN')" :description="__('Enable for shared scanners and production-room workstation accounts.')" />
            <flux:switch wire:model="isAdmin" :label="__('Administrator')" :description="__('Administrators can manage equipment, stocks, templates, jobs, and users.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save user') }}</flux:button>
            </div>
        </form>
    </flux:modal>

</div>
