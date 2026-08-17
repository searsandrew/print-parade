<?php

use App\Models\User;

test('the public print station renders the scanner friendly submission shell', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('print.station'))
        ->assertOk()
        ->assertSee('Print labels')
        ->assertSee('Select a label')
        ->assertSee('Select a printer')
        ->assertSee('Select your name')
        ->assertSee('Queue print job')
        ->assertSee('x-data="printStation"', false);
});

test('guests are redirected to login before using the print station', function () {
    $this->get(route('print.station'))->assertRedirect(route('login'));
});
