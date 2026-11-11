<?php

namespace Modules\Access\Services;

use App\Exceptions\DomainRuleException;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityPolicy;
use App\Support\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Port of front security_service.ts securityPolicyService.save + the security log filters. */
class SecurityAdminService
{
    public const POLICY_LABELS = [
        'passwordExpirationEnabled' => 'تفعيل انتهاء كلمة المرور', 'passwordExpirationDays' => 'مدة صلاحية كلمة المرور', 'passwordExpirationWarningDays' => 'الإشعار قبل الانتهاء',
        'minimumPasswordLength' => 'الحد الأدنى للطول', 'requireUppercase' => 'حرف كبير', 'requireLowercase' => 'حرف صغير', 'requireNumber' => 'رقم', 'requireSpecialCharacter' => 'رمز خاص', 'preventCommonPasswords' => 'منع الشائعة',
        'passwordHistoryEnabled' => 'منع إعادة الاستخدام', 'passwordHistoryCount' => 'عدد كلمات المرور السابقة', 'minimumPasswordAge' => 'الحد الأدنى لمدة الاستخدام',
        'maxFailedLoginAttempts' => 'المحاولات قبل القفل', 'lockoutDuration' => 'مدة القفل', 'idleTimeoutEnabled' => 'إنهاء الجلسة بعد عدم النشاط', 'idleTimeoutMinutes' => 'مدة عدم النشاط',
        'idleWarningMinutes' => 'التنبيه قبل الخروج', 'maximumSessionLifetime' => 'الحد الأقصى لمدة الجلسة', 'concurrentSessionLimit' => 'الجلسات المتزامنة', 'mfaPolicy' => 'سياسة MFA', 'mfaRequiredRoles' => 'أدوار MFA الإلزامية',
    ];

    public const BOOL_KEYS = ['passwordExpirationEnabled', 'requireUppercase', 'requireLowercase', 'requireNumber', 'requireSpecialCharacter', 'preventCommonPasswords', 'passwordHistoryEnabled', 'idleTimeoutEnabled'];

    public const INT_KEYS = ['passwordExpirationDays', 'passwordExpirationWarningDays', 'minimumPasswordLength', 'passwordHistoryCount', 'minimumPasswordAge', 'maxFailedLoginAttempts', 'lockoutDuration', 'idleTimeoutMinutes', 'idleWarningMinutes', 'maximumSessionLifetime', 'concurrentSessionLimit'];

    /** Saves the policy; returns [policy, changedCount]. Input keys are already type-validated by the controller. */
    public function savePolicy(array $input, User $actor): array
    {
        $prev = SecurityPolicy::get();
        $next = $prev;
        foreach (self::BOOL_KEYS as $k) {
            if (array_key_exists($k, $input)) {
                $next[$k] = (bool) $input[$k];
            }
        }
        foreach (self::INT_KEYS as $k) {
            if (array_key_exists($k, $input)) {
                $next[$k] = (int) $input[$k];
            }
        }
        if (array_key_exists('mfaPolicy', $input)) {
            $next['mfaPolicy'] = $input['mfaPolicy'];
        }
        if (array_key_exists('mfaRequiredRoles', $input)) {
            $next['mfaRequiredRoles'] = array_values(array_unique($input['mfaRequiredRoles'] ?? []));
        }
        if ($err = SecurityPolicy::validate($next)) {
            throw new DomainRuleException($err);
        }
        $changed = array_values(array_filter(array_keys($next), fn ($k) => json_encode($prev[$k] ?? null) !== json_encode($next[$k])));
        if (! $changed) {
            return [$prev, 0];
        }
        DB::transaction(function () use ($next, $prev, $changed, $actor) {
            $row = DB::table('security_policies')->orderBy('id')->lockForUpdate()->first();
            if ($row) {
                DB::table('security_policies')->where('id', $row->id)->update(['policy' => json_encode($next, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            } else {
                DB::table('security_policies')->insert(['policy' => json_encode($next, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
            SecurityLog::log('SECURITY_POLICY_CHANGED', userName: $actor->name, actor: $actor,
                target: implode('، ', array_map(fn ($k) => self::POLICY_LABELS[$k] ?? $k, $changed)),
                details: ['before' => array_intersect_key($prev, array_flip($changed)), 'after' => array_intersect_key($next, array_flip($changed))]);
        });
        SecurityPolicy::forget();

        return [SecurityPolicy::get(), count($changed)];
    }

    /** Security log, newest first, with the front security_log_tab filters (q, type, result, from, to) and optional userId. */
    public function log(array $f): Collection
    {
        $labels = raha('rbac.securityEventLabels', []);
        $rows = SecurityEvent::query()
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['result'] ?? null, fn ($q, $v) => $q->where('result', $v))
            ->when($f['userId'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('at', '<=', Carbon::parse($v)->endOfDay()))
            ->orderByDesc('at')->orderByDesc('id')->get();
        $q = $f['q'] ?? null;

        return $rows->filter(fn (SecurityEvent $e) => Text::matches("{$e->user_name} {$e->by} ".($e->target ?? '').' '.($labels[$e->type] ?? $e->type), $q))->values();
    }
}
