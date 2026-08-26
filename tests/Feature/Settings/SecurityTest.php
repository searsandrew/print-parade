<?php

test('local security routes are unavailable when microsoft manages authentication', function () {
    expect(app('router')->has('security.edit'))->toBeFalse()
        ->and(app('router')->has('password.request'))->toBeFalse()
        ->and(app('router')->has('passkey.login'))->toBeFalse()
        ->and(app('router')->has('two-factor.login'))->toBeFalse();
});
