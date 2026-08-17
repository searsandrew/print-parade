<?php

use App\Models\User;

test('a selected user can verify their print job pin', function () {
    $user = User::factory()->create();

    $user->assignPin('4826');
    $user->save();

    expect($user->verifiesPin('4826'))->toBeTrue()
        ->and($user->verifiesPin('1111'))->toBeFalse();
});

test('a print job pin is never stored or serialized in plain text', function () {
    $user = User::factory()->create();

    $user->assignPin('4826');
    $user->save();

    expect($user->getRawOriginal('pin_hash'))->not->toBe('4826')
        ->and($user->toArray())->not->toHaveKey('pin_hash');
});

test('a print job pin must contain between four and eight digits', function (string $pin) {
    User::factory()->create()->assignPin($pin);
})->with(['123', '123456789', '12ab'])->throws(InvalidArgumentException::class);

test('a user print job pin can be removed', function () {
    $user = User::factory()->create();
    $user->assignPin('4826');
    $user->save();

    $user->removePin();
    $user->save();

    expect($user->verifiesPin('4826'))->toBeFalse();
});

test('different users may choose the same print job pin', function () {
    $firstUser = User::factory()->create();
    $firstUser->assignPin('4826');
    $firstUser->save();

    $secondUser = User::factory()->create();
    $secondUser->assignPin('4826');
    $secondUser->save();

    expect($firstUser->verifiesPin('4826'))->toBeTrue()
        ->and($secondUser->verifiesPin('4826'))->toBeTrue();
});
