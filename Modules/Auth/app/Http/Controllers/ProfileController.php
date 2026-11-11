<?php

namespace Modules\Auth\Http\Controllers;

use App\Exceptions\DomainRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Beneficiaries\Models\Beneficiary;

/** Beneficiary self-service profile (front authService.profile / updateProfile). The ID number is immutable here. */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->beneficiary($request)->toProfile()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:60'], 'fatherName' => ['nullable', 'string', 'max:60'], 'grandName' => ['nullable', 'string', 'max:60'],
            'familyName' => ['required', 'string', 'max:60'], 'birthDate' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', 'string', 'max:20'], 'nationality' => ['nullable', 'string', 'max:60'],
            'mobile' => ['nullable', 'regex:/^05\d{8}$/'], 'email' => ['nullable', 'email', 'max:120'],
            'city' => ['nullable', 'string', 'max:60'], 'district' => ['nullable', 'string', 'max:60'], 'address' => ['nullable', 'string', 'max:200'],
        ], ['mobile.regex' => 'رقم الجوال يجب أن يبدأ بـ 05 ويتكون من 10 أرقام.', 'email' => 'البريد الإلكتروني غير صحيح.', 'required' => 'أكمل الحقول المطلوبة.']);
        $b = $this->beneficiary($request);
        $b->update(collect($data)->mapWithKeys(fn ($v, $k) => [\Illuminate\Support\Str::snake($k) => (string) $v])->all());
        $second = $b->father_name ?: $b->family_name;
        $request->user()->forceFill([
            'name' => trim("{$b->first_name} {$second}"), 'initials' => mb_substr($b->first_name, 0, 1).mb_substr($second, 0, 1), 'mobile' => $b->mobile,
        ])->save();

        return response()->json(['data' => $b->toProfile(), 'user' => $request->user()->toDemoUser()]);
    }

    private function beneficiary(Request $request): Beneficiary
    {
        $user = $request->user();
        if (! $user->isBeneficiary()) {
            throw DomainRuleException::forbidden();
        }

        return Beneficiary::query()->findOrFail($user->beneficiary_id);
    }
}
