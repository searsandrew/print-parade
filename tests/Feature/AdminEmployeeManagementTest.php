<?php

use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

test('employee administration requires administrator access', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.employees'))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.employees'))
        ->assertOk()
        ->assertSee('Employees');
});

test('administrators can create and update an employee with a pin', function () {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test('pages::admin.employees')
        ->set('name', 'Amanda Operator')
        ->set('employeeNumber', 'EMP-0042')
        ->set('pin', '2468')
        ->set('pin_confirmation', '2468')
        ->call('saveEmployee')
        ->assertHasNoErrors();

    $employee = Employee::query()->sole();

    expect($employee->name)->toBe('Amanda Operator')
        ->and($employee->employee_number)->toBe('EMP-0042')
        ->and($employee->verifiesPin('2468'))->toBeTrue()
        ->and($employee->is_active)->toBeTrue();

    $component
        ->call('editEmployee', $employee->id)
        ->set('name', 'Amanda Smith')
        ->set('isActive', false)
        ->call('saveEmployee')
        ->assertHasNoErrors();

    expect($employee->refresh()->name)->toBe('Amanda Smith')
        ->and($employee->is_active)->toBeFalse()
        ->and($employee->verifiesPin('2468'))->toBeTrue();
});

test('employees may intentionally share a pin and pins can be removed', function () {
    $this->actingAs(User::factory()->admin()->create());

    foreach (['First Operator', 'Second Operator'] as $name) {
        Livewire::test('pages::admin.employees')
            ->set('name', $name)
            ->set('pin', '1357')
            ->set('pin_confirmation', '1357')
            ->call('saveEmployee')
            ->assertHasNoErrors();
    }

    $employees = Employee::query()->orderBy('id')->get();
    expect($employees[0]->verifiesPin('1357'))->toBeTrue()
        ->and($employees[1]->verifiesPin('1357'))->toBeTrue();

    Livewire::test('pages::admin.employees')
        ->call('editEmployee', $employees[0]->id)
        ->call('removePin')
        ->assertHasNoErrors();

    expect($employees[0]->refresh()->pin_hash)->toBeNull();
});

test('employee pin confirmation and format are validated', function (string $pin, string $confirmation) {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.employees')
        ->set('name', 'Invalid Employee')
        ->set('pin', $pin)
        ->set('pin_confirmation', $confirmation)
        ->call('saveEmployee')
        ->assertHasErrors('pin');
})->with([
    ['123', '123'],
    ['12ab', '12ab'],
    ['4826', '1111'],
]);
