<?php

namespace Modules\Settings\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Setting;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Permissions\PermissionResolver;
use Modules\Settings\Models\ContactMessage;

/**
 * Ports of front organization_service.ts, language_service.ts (system default), fast_track_service.ts (policy part),
 * contact_service.ts and catalog publicServices.
 */
class SettingsService
{
    public const MAX_LOGO_CHARS = 280_000;

    public const MAX_IMAGE_BYTES = 200 * 1024;

    public const IMAGE_KEYS = ['logo', 'signatureImage', 'stampImage'];

    public const DEFAULT_ORGANIZATION = [
        'nameAr' => 'جمعية الرعاية الصحية المنزلية – راحة', 'nameEn' => 'Home Healthcare Charity – RAHA', 'address' => '', 'phone' => '', 'email' => '',
        'registrationNumber' => '', 'updatedAt' => '', 'updatedBy' => 'النظام',
    ];

    public function __construct(private readonly PermissionResolver $permissions) {}

    /** front provider_branding isSafeLogo (+ decoded size ≤ 200KB). */
    public static function isSafeLogo(mixed $v): bool
    {
        if (! is_string($v) || strlen($v) > self::MAX_LOGO_CHARS || ! preg_match('/^data:image\/(?:png|jpeg);base64,([A-Za-z0-9+\/]+={0,2})$/', $v, $m)) {
            return false;
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return false;
        }
        // The bytes must really be the declared type (a text file labelled image/png would later break PDF generation).
        $isPng = str_starts_with($bytes, "�PNG

");
        $isJpeg = str_starts_with($bytes, "���");

        return str_starts_with($v, 'data:image/png;') ? $isPng : $isJpeg;
    }

    public function organization(): array
    {
        return array_merge(self::DEFAULT_ORGANIZATION, Setting::read('organization', []) ?: []);
    }

    /** $patch: OrganizationInput (partial). An image key set to null removes that image. */
    public function saveOrganization(array $patch, User $user): array
    {
        if (! $this->permissions->has($user, 'organization.manage')) {
            throw DomainRuleException::forbidden('لا تملك صلاحية تعديل هوية الجمعية.');
        }
        $next = array_merge($this->organization(), $patch);
        foreach (['nameAr', 'nameEn', 'address', 'phone', 'email', 'registrationNumber'] as $k) {
            $next[$k] = trim((string) ($next[$k] ?? ''));
        }
        if ($next['nameAr'] === '') {
            throw new DomainRuleException('اسم الجمعية مطلوب.');
        }
        if ($next['email'] !== '' && ! preg_match('/^\S+@\S+\.\S+$/', $next['email'])) {
            throw new DomainRuleException('البريد الإلكتروني غير صحيح.');
        }
        foreach (self::IMAGE_KEYS as $k) {
            if (isset($next[$k]) && ! self::isSafeLogo($next[$k])) {
                throw new DomainRuleException('الصورة يجب أن تكون PNG أو JPG صالحة وبحجم لا يتجاوز 200 كيلوبايت.');
            }
            if (! isset($next[$k])) {
                unset($next[$k]);
            }
        }
        $next = array_intersect_key($next, array_flip(array_merge(array_keys(self::DEFAULT_ORGANIZATION), self::IMAGE_KEYS)));
        $next['updatedAt'] = now_iso();
        $next['updatedBy'] = $user->name;
        Setting::write('organization', $next, $user->name);
        Activity::audit('تم تعديل هوية الجمعية', 'إعدادات', 'organization', $user);

        return $next;
    }

    public function defaultLang(): string
    {
        $lang = Setting::read('language', [])['defaultLang'] ?? 'ar';

        return in_array($lang, ['ar', 'en'], true) ? $lang : 'ar';
    }

    /** PUT language — the mock had no permission check; security.manage is required here. */
    public function saveLanguage(string $lang, User $user): string
    {
        if (! $this->permissions->has($user, 'security.manage')) {
            throw DomainRuleException::forbidden('ليست لديك صلاحية تنفيذ هذا الإجراء.');
        }
        $prev = $this->defaultLang();
        Setting::write('language', ['defaultLang' => $lang], $user->name);
        if ($prev !== $lang) {
            Activity::audit('تم تعديل اللغة الافتراضية للنظام', 'إعدادات', 'language', $user, ['before' => $prev, 'after' => $lang]);
        }

        return $lang;
    }

    public function fastTrackPolicy(): array
    {
        $seed = raha('fast_track.fastTrackPolicySeed', []);
        $stored = Setting::read('fast_track');
        if (! $stored || ($stored['configVersion'] ?? 1) < raha('fast_track.FAST_TRACK_CONFIG_VERSION', 2)) {
            return $seed;
        }

        return $stored;
    }

    /** Fail-safe: any incomplete configuration disables Fast Track routing. */
    public static function activeEntryStage(array $policy): ?string
    {
        return ! empty($policy['enabled']) && ! empty($policy['entryStage']) ? $policy['entryStage'] : null;
    }

    public function saveFastTrack(array $next, User $user): array
    {
        if (! $this->permissions->has($user, 'security.manage')) {
            throw DomainRuleException::forbidden('لا تملك صلاحية إدارة إعدادات المسار السريع.');
        }
        $stage = (string) ($next['entryStage'] ?? '');
        if ($next['enabled'] && $stage === '') {
            throw new DomainRuleException('حدد مرحلة الاعتماد المستهدفة قبل تفعيل المسار السريع.');
        }
        $prev = $this->fastTrackPolicy();
        $policy = [
            'enabled' => (bool) $next['enabled'], 'entryStage' => $stage, 'requireApprovedPrevious' => (bool) $next['requireApprovedPrevious'],
            'configVersion' => raha('fast_track.FAST_TRACK_CONFIG_VERSION', 2), 'updatedAt' => now_iso(), 'updatedBy' => $user->name,
        ];
        Setting::write('fast_track', $policy, $user->name);
        // The mock wrote no audit row; policy changes are audited here.
        $keys = ['enabled', 'entryStage', 'requireApprovedPrevious'];
        Activity::audit('تم تعديل إعدادات المسار السريع', 'إعدادات', 'fast_track', $user, [
            'before' => array_intersect_key($prev, array_flip($keys)), 'after' => array_intersect_key($policy, array_flip($keys)),
        ]);

        return $policy;
    }

    /** front validateContact: returns field => Arabic message. */
    public static function validateContact(array $m): array
    {
        $e = [];
        if (mb_strlen(trim($m['name'])) < 2) {
            $e['name'] = 'يرجى إدخال الاسم';
        }
        if (! preg_match('/^05\d{8}$/', $m['mobile'])) {
            $e['mobile'] = 'رقم الجوال يجب أن يبدأ بـ 05 ويتكون من ١٠ أرقام';
        }
        if ($m['email'] !== '' && ! preg_match('/^\S+@\S+\.\S+$/', $m['email'])) {
            $e['email'] = 'البريد الإلكتروني غير صحيح';
        }
        if (mb_strlen(trim($m['subject'])) < 2) {
            $e['subject'] = 'يرجى إدخال الموضوع';
        }
        if (mb_strlen(trim($m['message'])) < 10) {
            $e['message'] = 'يرجى كتابة رسالة لا تقل عن ١٠ أحرف';
        }

        return $e;
    }

    public function contact(array $m, ?string $ip): ContactMessage
    {
        $errors = self::validateContact($m);
        if ($errors) {
            throw new DomainRuleException(reset($errors), 422, ['errors' => array_map(fn ($v) => [$v], $errors)]);
        }

        return ContactMessage::query()->create([
            'name' => trim($m['name']), 'mobile' => $m['mobile'], 'email' => $m['email'] !== '' ? trim($m['email']) : null,
            'subject' => trim($m['subject']), 'message' => trim($m['message']), 'ip' => $ip,
        ]);
    }
}
