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

test('printing settings explain that employee pins are administered separately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.printing')
        ->assertSee('Employee identities and PINs are managed by an administrator')
        ->assertDontSee('New PIN');
});

test('printing settings never expose employee pin fields', function (string $pin, string $confirmation) {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.printing')
        ->assertDontSee($pin)
        ->assertDontSee($confirmation)
        ->assertDontSee('Confirm PIN');
})->with([
    ['123', '123'],
    ['12ab', '12ab'],
    ['4826', '1111'],
]);

test('guests cannot access printing settings', function () {
    $this->get(route('printing.edit'))->assertRedirect(route('login'));
});
