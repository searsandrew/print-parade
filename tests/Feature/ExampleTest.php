<?php

test('the application entry point sends guests to login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});
