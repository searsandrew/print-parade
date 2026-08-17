<?php

use App\Models\User;

test('guests are redirected from administration', function () {
    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('login'));
});

test('non administrators cannot access administration', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('administrators can access administration', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Administration')
        ->assertSee('Bridges &amp; printers', false);
});

test('administration navigation is only shown to administrators', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee(route('admin.dashboard'));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertSee(route('admin.dashboard'));
});
