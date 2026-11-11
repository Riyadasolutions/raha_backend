<?php

namespace Modules\Beneficiaries\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Beneficiaries\Services\BeneficiaryDirectory;

/** Staff-side beneficiary files (front beneficiaries_page / beneficiary_profile_page). */
class BeneficiaryController extends Controller
{
    public function __construct(private readonly BeneficiaryDirectory $directory) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->directory->list($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:60'], 'fatherName' => ['required', 'string', 'max:60'], 'grandName' => ['nullable', 'string', 'max:60'],
            'familyName' => ['required', 'string', 'max:60'], 'nationalId' => ['required', 'regex:/^[12]\d{9}$/'], 'idType' => ['nullable', 'string', 'max:30'],
            'birthDate' => ['nullable', 'date_format:Y-m-d', 'before:today'], 'gender' => ['nullable', 'in:ذكر,أنثى'], 'nationality' => ['nullable', 'string', 'max:60'],
            'mobile' => ['required', 'regex:/^05\d{8}$/'], 'email' => ['nullable', 'regex:/^\S+@\S+\.\S+$/', 'max:120'],
            'city' => ['required', 'string', 'max:60'], 'district' => ['nullable', 'string', 'max:60'], 'address' => ['nullable', 'string', 'max:200'],
        ], [
            'required' => 'حقل مطلوب.', 'nationalId.required' => 'رقم هوية من ١٠ أرقام يبدأ بـ 1 أو 2.', 'nationalId.regex' => 'رقم هوية من ١٠ أرقام يبدأ بـ 1 أو 2.',
            'mobile.required' => 'رقم جوال صحيح يبدأ بـ 05.', 'mobile.regex' => 'رقم جوال صحيح يبدأ بـ 05.', 'email.regex' => 'بريد إلكتروني غير صحيح.',
            'birthDate.date_format' => 'تاريخ الميلاد غير صحيح.', 'birthDate.before' => 'تاريخ الميلاد غير صحيح.', 'gender.in' => 'الجنس غير صحيح.',
        ]);
        $user = $request->user();
        $b = $this->directory->create($data, $user);

        return response()->json(['data' => $this->directory->record($b, $user)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->directory->record($this->directory->find($id, $user), $user)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'], 'mobile' => ['sometimes', 'nullable', 'regex:/^05\d{8}$/'],
            'email' => ['sometimes', 'nullable', 'regex:/^\S+@\S+\.\S+$/', 'max:120'], 'nationality' => ['sometimes', 'nullable', 'string', 'max:60'],
            'city' => ['sometimes', 'nullable', 'string', 'max:60'], 'district' => ['sometimes', 'nullable', 'string', 'max:60'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'], 'nationalId' => ['sometimes', 'required', 'regex:/^[12]\d{9}$/'],
            'birthDate' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'], 'gender' => ['sometimes', 'nullable', 'in:ذكر,أنثى'],
            'status' => ['sometimes', 'required', 'in:active,inactive,suspended'],
        ], [
            'name.required' => 'يرجى إدخال الاسم', 'mobile.regex' => 'رقم الجوال يجب أن يبدأ بـ 05 ويتكون من ١٠ أرقام', 'email.regex' => 'صيغة البريد غير صحيحة',
            'nationalId.required' => 'رقم الهوية يجب أن يتكون من ١٠ أرقام ويبدأ بـ 1 أو 2', 'nationalId.regex' => 'رقم الهوية يجب أن يتكون من ١٠ أرقام ويبدأ بـ 1 أو 2',
            'birthDate.date_format' => 'تاريخ الميلاد غير صحيح.', 'birthDate.before' => 'تاريخ الميلاد غير صحيح.', 'gender.in' => 'الجنس غير صحيح.', 'status.in' => 'حالة المستفيد غير صحيحة.',
        ]);
        $data = array_map(fn ($v) => $v ?? '', $data);
        $user = $request->user();
        $b = $this->directory->update($id, $data, $user);

        return response()->json(['data' => $this->directory->record($b, $user)]);
    }

    public function requests(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->directory->requests($this->directory->find($id, $user), $user)]);
    }

    public function documents(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->directory->documents($this->directory->find($id, $user), $user)]);
    }

    public function financials(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->directory->financials($this->directory->find($id, $user), $user)]);
    }

    public function notes(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->directory->notes($this->directory->find($id, $user), $user)]);
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:3', 'max:2000'], 'visibility' => ['nullable', 'in:internal,beneficiary'],
        ], ['text.required' => 'يرجى كتابة الملاحظة', 'text.min' => 'يرجى كتابة الملاحظة', 'visibility.in' => 'نطاق ظهور الملاحظة غير صحيح.']);
        $user = $request->user();
        $b = $this->directory->find($id, $user);
        $note = $this->directory->addNote($b, $user, trim($data['text']), $data['visibility'] ?? 'internal');

        return response()->json(['data' => $note], 201);
    }

    public function activity(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->directory->activity($this->directory->find($id, $user), $user)]);
    }
}
