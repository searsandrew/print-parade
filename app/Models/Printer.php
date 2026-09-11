<?php

namespace App\Models;

use App\Labels\Enums\PrinterLanguage;
use Database\Factories\PrinterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $print_bridge_id
 * @property int|null $label_stock_id
 * @property string $name
 * @property string|null $location
 * @property PrinterLanguage $language
 * @property int $dpi
 * @property string $horizontal_correction in millimeters
 * @property string $vertical_correction in millimeters
 * @property string $bridge_identifier
 * @property bool $is_active
 * @property-read PrintBridge $printBridge
 */
#[Fillable(['print_bridge_id', 'label_stock_id', 'name', 'location', 'language', 'dpi', 'horizontal_correction', 'vertical_correction', 'bridge_identifier', 'is_active'])]
class Printer extends Model
{
    /** @use HasFactory<PrinterFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'language' => PrinterLanguage::class,
            'dpi' => 'integer',
            'horizontal_correction' => 'decimal:3',
            'vertical_correction' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $printer): void {
            if (! in_array($printer->dpi, [203, 300], true)) {
                throw new LogicException('A printer must use a supported resolution of 203 or 300 DPI.');
            }

            $verticalCorrectionDots = (int) round((float) $printer->vertical_correction / 25.4 * $printer->dpi);

            if (abs($verticalCorrectionDots) > 120) {
                throw new LogicException('A printer vertical correction cannot exceed 120 dots.');
            }
        });
    }

    /**
     * @return HasMany<PrintJob, $this>
     */
    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    /** @return BelongsTo<PrintBridge, $this> */
    public function printBridge(): BelongsTo
    {
        return $this->belongsTo(PrintBridge::class);
    }

    /** @return BelongsTo<LabelStock, $this> */
    public function labelStock(): BelongsTo
    {
        return $this->belongsTo(LabelStock::class);
    }
}
