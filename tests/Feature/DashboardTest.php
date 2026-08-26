<?php

test('the retired dashboard route is unavailable', function () {
    $this->get('/dashboard')->assertNotFound();
});
