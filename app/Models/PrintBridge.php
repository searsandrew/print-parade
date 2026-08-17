<?php

namespace App\Models;

use Database\Factories\PrintBridgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** @property int $id @property string $name @property string|null $token_hash @property bool $is_active @property Carbon|null $last_seen_at */
#[Fillable(['name', 'is_active'])]
#[Hidden(['token_hash'])]
class PrintBridge extends Model
{
    /** @use HasFactory<PrintBridgeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'immutable_datetime'];
    }

    public function issueToken(): string
    {
        $token = Str::random(64);
        $this->forceFill(['token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public static function findActiveByToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return self::query()->where('is_active', true)->where('token_hash', hash('sha256', $token))->first();
    }

    /** @return HasMany<Printer, $this> */
    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    /** @return HasMany<PrintJob, $this> */
    public function claimedPrintJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'claimed_by_bridge');
    }
}
