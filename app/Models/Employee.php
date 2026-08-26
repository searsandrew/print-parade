<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $employee_number
 * @property string|null $pin_hash
 * @property bool $is_active
 * @property int $operated_print_jobs_count
 */
#[Fillable(['user_id', 'name', 'employee_number', 'is_active'])]
#[Hidden(['pin_hash'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function assignPin(string $pin): void
    {
        if (preg_match('/\A\d{4,8}\z/', $pin) !== 1) {
            throw new InvalidArgumentException('The PIN must contain between 4 and 8 digits.');
        }

        $this->pin_hash = self::hashPin($pin);
    }

    public function removePin(): void
    {
        $this->pin_hash = null;
    }

    public function verifiesPin(string $pin): bool
    {
        if ($this->pin_hash === null || preg_match('/\A\d{4,8}\z/', $pin) !== 1) {
            return false;
        }

        return hash_equals($this->pin_hash, self::hashPin($pin));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PrintJob, $this> */
    public function operatedPrintJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'operated_by_employee_id');
    }

    private static function hashPin(string $pin): string
    {
        return hash_hmac('sha256', $pin, (string) config('app.key'));
    }
}
