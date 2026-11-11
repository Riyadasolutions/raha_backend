<?php

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * One account table for staff, providers and beneficiaries (front: StaffUser / DemoUser / BeneficiaryProfile login).
 * Account type is separate from RBAC roles (front lib/account_type.ts).
 */
class User extends Authenticatable
{
    use HasApiTokens;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['password', 'password_history', 'remember_token'];

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'password' => 'hashed',
            'password_history' => 'array',
            'active' => 'boolean',
            'suspended' => 'boolean',
            'must_change_password' => 'boolean',
            'mfa_enabled' => 'boolean',
            'failed_attempts' => 'integer',
            'locked_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('position')->orderByPivot('position');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function isStaff(): bool
    {
        return $this->account_type === AccountType::Staff;
    }

    public function isProvider(): bool
    {
        return $this->account_type === AccountType::Provider;
    }

    public function isBeneficiary(): bool
    {
        return $this->account_type === AccountType::Beneficiary;
    }

    /** Lock is active while locked_at is set and locked_until is empty (admin unlock) or in the future. */
    public function isLocked(): bool
    {
        return $this->locked_at !== null && ($this->locked_until === null || $this->locked_until->isFuture());
    }

    public function roleIds(): array
    {
        return $this->roles->pluck('id')->all();
    }

    /** Front DemoUser shape. */
    public function toDemoUser(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'roleLabel' => $this->role_label,
            'department' => $this->department,
            'initials' => $this->initials,
            'beneficiaryId' => $this->beneficiary_id,
            'providerId' => $this->provider_id,
        ], fn ($v) => $v !== null);
    }
}
