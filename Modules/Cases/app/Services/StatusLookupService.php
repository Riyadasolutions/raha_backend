<?php

namespace Modules\Cases\Services;

use App\Models\User;
use App\Support\Permissions\PermissionResolver;
use Modules\Cases\Models\RahaCase;

/**
 * Read-only request status lookup (port of front request_status_service.ts). Exact request-number match only;
 * returns a minimal summary and writes nothing (no audit, notification, task or timestamp change).
 */
class StatusLookupService
{
    public const PERMISSION = 'requests.statusLookup';

    private const FORMAT = '/^REQ(?:-[A-Z0-9]+)+$/';

    public const CATEGORY = [
        'new' => 'active', 'medical_review' => 'active', 'awaiting_quotation' => 'active', 'social_review' => 'active', 'manager_approval' => 'active', 'preliminary_approved' => 'active',
        'assigned' => 'in_service', 'scheduled' => 'in_service', 'in_progress' => 'in_service', 'completed' => 'completed', 'finance_review' => 'completed',
        'archived' => 'archived', 'rejected' => 'rejected', 'returned' => 'returned',
    ];

    public const CATEGORY_LABELS = [
        'active' => 'قيد المعالجة', 'in_service' => 'الخدمة جارية', 'completed' => 'الخدمة مكتملة — قيد الإغلاق', 'archived' => 'مؤرشف (مغلق)', 'rejected' => 'مرفوض', 'returned' => 'معاد للمستفيد للتصحيح',
    ];

    public function __construct(private PermissionResolver $perms, private CaseAccess $access) {}

    public function canLookup(?User $u): bool
    {
        return $u !== null && $u->isStaff() && $this->perms->has($u, self::PERMISSION);
    }

    public static function normalize(?string $input): string
    {
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', '', (string) $input);
        $s = preg_replace_callback('/[\x{0660}-\x{0669}]/u', fn ($m) => (string) (mb_ord($m[0]) - 0x660), $s);

        return strtoupper(trim($s));
    }

    public static function stage(RahaCase $c): string
    {
        if ($c->status === 'preliminary_approved') {
            $prelim = WorkflowConfig::stageLabel('preliminary_approved');

            return $c->finance ? (raha('finance.financeStageLabels.'.($c->finance['stage'] ?? '')) ?? $prelim) : $prelim;
        }
        if (in_array($c->status, ['medical_review', 'social_review', 'manager_approval'], true)) {
            return WorkflowConfig::currentStageLabel($c->status, $c->stage);
        }

        return WorkflowConfig::stageLabel($c->status) ?? WorkflowConfig::currentStageLabel($c->status, $c->stage);
    }

    public function summarize(RahaCase $c, bool $canOpenDetail): array
    {
        $category = self::CATEGORY[$c->status] ?? 'active';

        return [
            'requestNumber' => $c->id, 'statusLabel' => self::CATEGORY_LABELS[$category], 'category' => $category, 'stageLabel' => self::stage($c),
            'submittedAt' => $c->created_at?->utc()->toDateString(), 'lastUpdatedAt' => $c->updated_at?->utc()->toDateString(), 'canOpenDetail' => $canOpenDetail,
        ];
    }

    /** @return array{outcome: string, summary?: array} outcome: denied | empty | invalid | not_found | found */
    public function lookup(?User $user, ?string $input): array
    {
        if (! $this->canLookup($user)) {
            return ['outcome' => 'denied'];
        }
        $number = self::normalize($input);
        if ($number === '') {
            return ['outcome' => 'empty'];
        }
        if (! preg_match(self::FORMAT, $number)) {
            return ['outcome' => 'invalid'];
        }
        $c = RahaCase::query()->with('tasks')->whereRaw('UPPER(id) = ?', [$number])->first();
        if (! $c) {
            return ['outcome' => 'not_found'];
        }

        return ['outcome' => 'found', 'summary' => $this->summarize($c, $this->access->canAccessCase($c, $user))];
    }
}
