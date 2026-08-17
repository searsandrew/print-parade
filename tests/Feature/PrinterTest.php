<?php

use App\Labels\Enums\PrinterLanguage;
use App\Models\Printer;

test('a printer stores its rendering and bridge configuration', function () {
    $printer = Printer::factory()->create([
        'name' => 'Packing Zebra ZT411',
        'location' => 'Packing Station 1',
        'language' => PrinterLanguage::Zpl,
        'dpi' => 300,
        'bridge_identifier' => 'packing-zebra-01',
        'is_active' => true,
    ]);

    expect($printer->name)->toBe('Packing Zebra ZT411')
        ->and($printer->location)->toBe('Packing Station 1')
        ->and($printer->language)->toBe(PrinterLanguage::Zpl)
        ->and($printer->dpi)->toBe(300)
        ->and($printer->bridge_identifier)->toBe('packing-zebra-01')
        ->and($printer->is_active)->toBeTrue();
});

test('printer factories describe supported future output languages', function () {
    expect(Printer::factory()->dpl()->create()->language)->toBe(PrinterLanguage::Dpl)
        ->and(Printer::factory()->raster()->create()->language)->toBe(PrinterLanguage::Raster)
        ->and(Printer::factory()->inactive()->create()->is_active)->toBeFalse();
});

test('printers only accept supported resolutions', function (int $dpi) {
    Printer::factory()->create(['dpi' => $dpi]);
})->with([200, 600])->throws(
    LogicException::class,
    'A printer must use a supported resolution of 203 or 300 DPI.',
);
