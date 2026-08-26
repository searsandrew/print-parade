<?php

use App\Models\Employee;

test('a selected employee can verify their print job pin', function () {
    $employee = Employee::factory()->create();
    $employee->assignPin('4826');
    $employee->save();

    expect($employee->verifiesPin('4826'))->toBeTrue()
        ->and($employee->verifiesPin('1111'))->toBeFalse();
});

test('an employee print job pin is never stored or serialized in plain text', function () {
    $employee = Employee::factory()->create();
    $employee->assignPin('4826');
    $employee->save();

    expect($employee->getRawOriginal('pin_hash'))->not->toBe('4826')
        ->and($employee->toArray())->not->toHaveKey('pin_hash');
});

test('an employee print job pin must contain between four and eight digits', function (string $pin) {
    Employee::factory()->create()->assignPin($pin);
})->with(['123', '123456789', '12ab'])->throws(InvalidArgumentException::class);

test('an employee print job pin can be removed', function () {
    $employee = Employee::factory()->create();
    $employee->assignPin('4826');
    $employee->save();
    $employee->removePin();
    $employee->save();

    expect($employee->verifiesPin('4826'))->toBeFalse();
});

test('different employees may choose the same print job pin', function () {
    $firstEmployee = Employee::factory()->create();
    $firstEmployee->assignPin('4826');
    $firstEmployee->save();
    $secondEmployee = Employee::factory()->create();
    $secondEmployee->assignPin('4826');
    $secondEmployee->save();

    expect($firstEmployee->verifiesPin('4826'))->toBeTrue()
        ->and($secondEmployee->verifiesPin('4826'))->toBeTrue();
});
