<?php

namespace Modules\Beneficiaries\Models;

use App\Models\User;
use App\Support\Serialization\FrontSerializable;
use App\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One beneficiary. Serves two front shapes:
 *  - BeneficiaryProfile (portal self-service, toProfile)
 *  - BeneficiaryRecord (staff view, toRecord; masked without beneficiaries.viewSensitive)
 */
class Beneficiary extends Model
{
    use FrontSerializable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['has_portal_account' => 'boolean'];
    }

    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'beneficiary_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(BeneficiaryNote::class)->orderByDesc('at');
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->father_name, $this->grand_name, $this->family_name])));
    }

    public function toProfile(): array
    {
        return [
            'id' => $this->id, 'firstName' => $this->first_name, 'fatherName' => $this->father_name, 'grandName' => $this->grand_name,
            'familyName' => $this->family_name, 'nationalId' => $this->national_id, 'idType' => $this->id_type, 'birthDate' => $this->birth_date,
            'gender' => $this->gender, 'nationality' => $this->nationality, 'mobile' => $this->mobile, 'email' => $this->email,
            'city' => $this->city, 'district' => $this->district, 'address' => $this->address,
        ];
    }

    public function toRecord(bool $sensitive = true): array
    {
        return [
            'id' => $this->id, 'name' => $this->fullName(),
            'nationalId' => $sensitive ? $this->national_id : Text::mask($this->national_id),
            'mobile' => $sensitive ? $this->mobile : Text::mask($this->mobile),
            'email' => $sensitive ? $this->email : ($this->email ? mb_substr($this->email, 0, 1).'•••@•••' : ''),
            'birthDate' => $this->birth_date, 'gender' => $this->gender, 'nationality' => $this->nationality, 'city' => $this->city,
            'district' => $this->district, 'address' => $sensitive ? $this->address : '', 'status' => $this->status,
            'createdAt' => iso($this->created_at), 'hasPortalAccount' => $this->has_portal_account, 'masked' => ! $sensitive,
        ];
    }
}
