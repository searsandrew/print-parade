<?php

test('the public print station renders the scanner friendly submission shell', function () {
    $this->get(route('print.station'))
        ->assertOk()
        ->assertSee('Print labels')
        ->assertSee('Select a label')
        ->assertSee('Select a printer')
        ->assertSee('Select your name')
        ->assertSee('Queue print job')
        ->assertSee('x-data="printStation"', false);
});
