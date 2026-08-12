<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('a user can be found by their print job pin', function () {
    $user = User::factory()->create();

    $user->assignPin('4826');
    $user->save();

    expect(User::findByPin('4826'))->toBeInstanceOf(User::class)
        ->id->toBe($user->id)
        ->and(User::findByPin('1111'))->toBeNull();
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

    expect(User::findByPin('4826'))->toBeNull();
});

test('print job pins must be unique', function () {
    $firstUser = User::factory()->create();
    $firstUser->assignPin('4826');
    $firstUser->save();

    $secondUser = User::factory()->create();
    $secondUser->assignPin('4826');

    expect(fn () => $secondUser->save())->toThrow(UniqueConstraintViolationException::class);
});
