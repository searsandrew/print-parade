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

test('administration uses the menu bar without starter navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertSee(route('admin.printers'))
        ->assertSee(route('admin.print-jobs'))
        ->assertSee(route('print.station'))
        ->assertSee('Manage profile')
        ->assertDontSee('Repository')
        ->assertDontSee('Documentation')
        ->assertDontSee('Dashboard');
});
