<?php

namespace Modules\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Settings\Services\SettingsService;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function organization(Request $request): JsonResponse
    {
        $org = $this->settings->organization();
        // Signature and stamp images are staff-only (issued letters carry their own server-side snapshot).
        if (! $request->user()?->isStaff()) {
            unset($org['signatureImage'], $org['stampImage']);
        }

        return response()->json(['data' => $org]);
    }

    public function saveOrganization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nameAr' => ['sometimes', 'nullable', 'string', 'max:200'], 'nameEn' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'], 'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'string', 'max:190'], 'registrationNumber' => ['sometimes', 'nullable', 'string', 'max:60'],
            'logo' => ['sometimes', 'nullable', 'string'], 'signatureImage' => ['sometimes', 'nullable', 'string'], 'stampImage' => ['sometimes', 'nullable', 'string'],
        ], ['string' => 'البيانات المدخلة غير صحيحة.', 'max' => 'البيانات المدخلة طويلة جدًا.']);

        return response()->json(['data' => $this->settings->saveOrganization($data, $request->user()), 'message' => 'تم حفظ هوية الجمعية']);
    }

    public function language(): JsonResponse
    {
        return response()->json(['data' => ['defaultLang' => $this->settings->defaultLang()]]);
    }

    public function saveLanguage(Request $request): JsonResponse
    {
        $data = $request->validate(['defaultLang' => ['required', 'in:ar,en']], ['defaultLang' => 'اللغة غير مدعومة.']);

        return response()->json(['data' => ['defaultLang' => $this->settings->saveLanguage($data['defaultLang'], $request->user())], 'message' => 'تم حفظ اللغة الافتراضية']);
    }

    public function fastTrack(): JsonResponse
    {
        $policy = $this->settings->fastTrackPolicy();

        return response()->json([
            'data' => $policy, 'activeEntryStage' => SettingsService::activeEntryStage($policy),
            'entryOptions' => raha('fast_track.fastTrackEntryOptions', []), 'skippableStages' => raha('fast_track.skippableStages', []),
        ]);
    }

    public function saveFastTrack(Request $request): JsonResponse
    {
        $stages = array_column(raha('fast_track.fastTrackEntryOptions', []), 'value');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'entryStage' => ['present', 'nullable', 'string', 'in:'.implode(',', $stages)], 'requireApprovedPrevious' => ['required', 'boolean'],
        ], ['entryStage.in' => 'مرحلة الاعتماد المستهدفة غير صحيحة.', 'required' => 'أكمل الحقول المطلوبة.', 'present' => 'أكمل الحقول المطلوبة.']);
        $policy = $this->settings->saveFastTrack($data, $request->user());

        return response()->json(['data' => $policy, 'activeEntryStage' => SettingsService::activeEntryStage($policy), 'message' => 'تم حفظ إعدادات المسار السريع']);
    }

    /** GET /public/bootstrap — no auth. */
    public function bootstrap(): JsonResponse
    {
        $org = $this->settings->organization();

        return response()->json([
            'defaultLang' => $this->settings->defaultLang(),
            'organization' => array_filter(['nameAr' => $org['nameAr'], 'nameEn' => $org['nameEn'], 'logo' => $org['logo'] ?? null], fn ($v) => $v !== null),
        ]);
    }

    /** POST /public/contact {name, mobile, email, subject, message} — no auth, throttled. */
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'], 'mobile' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'string', 'max:190'],
            'subject' => ['nullable', 'string', 'max:200'], 'message' => ['nullable', 'string', 'max:5000'],
        ], ['max' => 'البيانات المدخلة طويلة جدًا.', 'string' => 'البيانات المدخلة غير صحيحة.']);
        $keys = ['name', 'mobile', 'email', 'subject', 'message'];
        $m = array_combine($keys, array_map(fn ($k) => (string) ($data[$k] ?? ''), $keys));
        $this->settings->contact($m, $request->ip());

        return response()->json(['delivered' => true, 'message' => 'تم إرسال رسالتك بنجاح'], 201);
    }
}
