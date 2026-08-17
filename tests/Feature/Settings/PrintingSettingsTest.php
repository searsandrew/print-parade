<?php

use App\Models\User;
use Livewire\Livewire;

test('printing settings page is displayed after password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('printing.edit'))
        ->assertOk()
        ->assertSee('Require operator selection and PIN');
});

test('printing settings require recent password confirmation', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('printing.edit'))
        ->assertRedirect();
});

test('an account can be configured as a shared print station', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.printing')
        ->set('requiresPrintOperatorPin', true)
        ->call('updatePrintMode')
        ->assertHasNoErrors();

    expect($user->refresh()->requires_print_operator_pin)->toBeTrue();
});

test('a user can set and remove their operator pin', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::settings.printing')
        ->set('pin', '4826')
        ->set('pin_confirmation', '4826')
        ->call('updatePin')
        ->assertHasNoErrors()
        ->assertSet('hasPrintPin', true)
        ->assertSet('pin', '')
        ->assertSet('pin_confirmation', '');

    expect($user->refresh()->verifiesPin('4826'))->toBeTrue();

    $component->call('removePin')
        ->assertHasNoErrors()
        ->assertSet('hasPrintPin', false);

    expect($user->refresh()->verifiesPin('4826'))->toBeFalse();
});

test('operator pin confirmation and format are validated', function (string $pin, string $confirmation) {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.printing')
        ->set('pin', $pin)
        ->set('pin_confirmation', $confirmation)
        ->call('updatePin')
        ->assertHasErrors('pin');
})->with([
    ['123', '123'],
    ['12ab', '12ab'],
    ['4826', '1111'],
]);

test('guests cannot access printing settings', function () {
    $this->get(route('printing.edit'))->assertRedirect(route('login'));
});
