<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $microsoft_tenant_id
 * @property string|null $microsoft_object_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $pin_hash
 * @property bool $requires_print_operator_pin
 * @property bool $is_admin
 * @property int $executed_print_jobs_count
 * @property int $submitted_print_jobs_count
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'requires_print_operator_pin'])]
#[Hidden(['password', 'pin_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'requires_print_operator_pin' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Set the PIN used to attribute print jobs to this user.
     */
    public function assignPin(string $pin): void
    {
        if (preg_match('/\A\d{4,8}\z/', $pin) !== 1) {
            throw new InvalidArgumentException('The PIN must contain between 4 and 8 digits.');
        }

        $this->pin_hash = self::hashPin($pin);
    }

    /**
     * Remove this user's print-job PIN.
     */
    public function removePin(): void
    {
        $this->pin_hash = null;
    }

    /**
     * Verify this user's print-job PIN.
     */
    public function verifiesPin(string $pin): bool
    {
        if ($this->pin_hash === null || preg_match('/\A\d{4,8}\z/', $pin) !== 1) {
            return false;
        }

        return hash_equals($this->pin_hash, self::hashPin($pin));
    }

    private static function hashPin(string $pin): string
    {
        return hash_hmac('sha256', $pin, (string) config('app.key'));
    }

    /**
     * @return HasMany<LabelTemplateVersion, $this>
     */
    public function labelTemplates(): HasMany
    {
        return $this->hasMany(LabelTemplateVersion::class);
    }

    /**
     * @return HasMany<PrintJob, $this>
     */
    public function executedPrintJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'executed_by');
    }

    /**
     * @return HasMany<PrintJob, $this>
     */
    public function submittedPrintJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'submitted_by');
    }
}
