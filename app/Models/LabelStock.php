<?php

namespace App\Models;

use App\Labels\Enums\LabelMediaType;
use Carbon\Carbon;
use Database\Factories\LabelStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $width in millimeters
 * @property string $height in millimeters
 * @property LabelMediaType $media_type
 * @property string|null $description
 * @property string|null $sku
 * @property bool $is_active
 * @property int $label_templates_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'width', 'height', 'media_type', 'description', 'sku', 'is_active'])]
class LabelStock extends Model
{
    /** @use HasFactory<LabelStockFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'media_type' => LabelMediaType::class,
            'is_active' => 'boolean',
        ];
    }

    public function widthInInches(): float
    {
        return (float) $this->width / 25.4;
    }

    public function heightInInches(): float
    {
        return (float) $this->height / 25.4;
    }

    /**
     * @return HasMany<LabelTemplate, $this>
     */
    public function labelTemplates(): HasMany
    {
        return $this->hasMany(LabelTemplate::class);
    }
}
