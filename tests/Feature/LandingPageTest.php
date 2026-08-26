<?php

use App\Models\User;

test('guests are sent directly to the login screen', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('basic users are sent directly to the print station', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('print.station'));
});

test('administrators are sent directly to administration', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('home'))
        ->assertRedirect(route('admin.dashboard'));
});

test('the retired dashboard and welcome screen are unavailable', function () {
    $this->get('/dashboard')->assertNotFound();
});
