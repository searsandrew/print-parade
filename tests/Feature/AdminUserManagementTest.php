<?php

use App\Models\PrintJob;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;

test('user administration requires administrator access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.users'))
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::admin.users')
        ->assertStatus(403);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.users'))
        ->assertOk()
        ->assertSee('Users');
});

test('public self registration is disabled', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();

    $this->get('/register')->assertNotFound();
});

test('administrators can create a verified shared station account', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.users')
        ->set('name', 'Warehouse Scanner')
        ->set('email', 'warehouse@example.test')
        ->set('password', 'Correct-Horse-Battery-Staple-9')
        ->set('password_confirmation', 'Correct-Horse-Battery-Staple-9')
        ->set('requiresPrintOperatorPin', true)
        ->call('saveUser')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'warehouse@example.test')->sole();

    expect($user->name)->toBe('Warehouse Scanner')
        ->and($user->requires_print_operator_pin)->toBeTrue()
        ->and($user->is_admin)->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Correct-Horse-Battery-Staple-9', $user->password))->toBeTrue();
});

test('administrators can update account mode and administrator access', function () {
    $this->actingAs(User::factory()->admin()->create());
    $user = User::factory()->create();
    $originalPassword = $user->password;

    Livewire::test('pages::admin.users')
        ->call('editUser', $user->id)
        ->set('name', 'Pat Operator')
        ->set('requiresPrintOperatorPin', true)
        ->set('isAdmin', true)
        ->call('saveUser')
        ->assertHasNoErrors();

    expect($user->refresh()->name)->toBe('Pat Operator')
        ->and($user->requires_print_operator_pin)->toBeTrue()
        ->and($user->is_admin)->toBeTrue()
        ->and($user->password)->toBe($originalPassword);
});

test('administrators cannot remove their own administrator access', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.users')
        ->call('editUser', $admin->id)
        ->set('isAdmin', false)
        ->call('saveUser')
        ->assertHasErrors(['isAdmin']);

    expect($admin->refresh()->is_admin)->toBeTrue();
});

test('administrators can set replace and remove operator pins', function () {
    $this->actingAs(User::factory()->admin()->create());
    $user = User::factory()->create();

    $component = Livewire::test('pages::admin.users')
        ->call('managePin', $user->id)
        ->set('pin', '2468')
        ->set('pin_confirmation', '2468')
        ->call('savePin')
        ->assertHasNoErrors();

    expect($user->refresh()->verifiesPin('2468'))->toBeTrue();

    $component
        ->call('managePin', $user->id)
        ->call('removePin')
        ->assertHasNoErrors();

    expect($user->refresh()->pin_hash)->toBeNull();
});

test('different users may intentionally share an operator pin', function () {
    $this->actingAs(User::factory()->admin()->create());
    $first = User::factory()->create();
    $second = User::factory()->create();

    foreach ([$first, $second] as $user) {
        Livewire::test('pages::admin.users')
            ->call('managePin', $user->id)
            ->set('pin', '1357')
            ->set('pin_confirmation', '1357')
            ->call('savePin')
            ->assertHasNoErrors();
    }

    expect($first->refresh()->verifiesPin('1357'))->toBeTrue()
        ->and($second->refresh()->verifiesPin('1357'))->toBeTrue();
});

test('user search and filters include print attribution counts', function () {
    $this->actingAs(User::factory()->admin()->create());
    $operator = User::factory()->create(['name' => 'Alex Operator']);
    $other = User::factory()->create(['name' => 'Taylor Person']);
    PrintJob::factory()->create([
        'submitted_by' => $operator->id,
        'executed_by' => $operator->id,
    ]);

    Livewire::test('pages::admin.users')
        ->set('search', 'Alex')
        ->set('accountType', 'personal')
        ->assertSee('Alex Operator')
        ->assertSee('1 job executed')
        ->assertSee('1 job submitted')
        ->assertDontSee('Taylor Person');
});

test('user account validation requires unique email and a confirmed strong password', function () {
    $this->actingAs(User::factory()->admin()->create());
    $existing = User::factory()->create();

    Livewire::test('pages::admin.users')
        ->set('name', 'Invalid User')
        ->set('email', $existing->email)
        ->set('password', 'short')
        ->set('password_confirmation', 'different')
        ->call('saveUser')
        ->assertHasErrors(['email', 'password']);
});
