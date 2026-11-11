<?php

namespace Modules\Cases\Services;

/**
 * Static workflow configuration (front mocks/workflow.ts + workflow_config.ts + raha_data.stageFor),
 * read from the shared raha('workflow.*') export.
 */
class WorkflowConfig
{
    /** front raha_data.stageOf: [stage label, default assignee]. */
    public const STAGE_OF = [
        'new' => ['التدقيق', 'محمد الحربي'], 'medical_review' => ['المراجعة الطبية', 'د. نورة القحطاني'], 'social_review' => ['الدراسة الاجتماعية', 'ريم الشهري'],
        'manager_approval' => ['اعتماد المدير', 'خالد الدوسري'], 'preliminary_approved' => ['بانتظار الاعتماد التالي', 'خالد الدوسري'], 'assigned' => ['التعيين', 'الفريق الطبي 01'],
        'scheduled' => ['تقديم الخدمة', 'الفريق الطبي 01'], 'in_progress' => ['تقديم الخدمة', 'الفريق الطبي 01'], 'completed' => ['علاقات المرضى', 'هدى الزهراني'],
        'finance_review' => ['المالية', 'عبدالله الغامدي'], 'archived' => ['الأرشفة', 'النظام'], 'returned' => ['المستفيد', 'المستفيد'], 'rejected' => ['مرفوض', '—'],
        'awaiting_quotation' => ['التسعير', 'د. نورة القحطاني'],
    ];

    /** front reviewDisplayLabels (currentStageLabel). */
    public const REVIEW_DISPLAY_LABELS = ['medical_review' => 'اللجنة الطبية', 'social_review' => 'البحث الاجتماعي', 'manager_approval' => 'الاعتماد المبدئي', 'preliminary_approved' => 'الاعتماد النهائي'];

    public const ACTIVE_TASK_STATUSES = ['new', 'in_progress'];

    /** @return array{0: string, 1: string} */
    public static function stageFor(string $status): array
    {
        return self::STAGE_OF[$status] ?? [$status, ''];
    }

    /** @return list<array<string, mixed>> */
    public static function actions(): array
    {
        return raha('workflow.actions', []);
    }

    public static function action(string $key): ?array
    {
        foreach (self::actions() as $a) {
            if ($a['key'] === $key) {
                return $a;
            }
        }

        return null;
    }

    /** @return array{status: string, role: string, review: string}|null */
    public static function reviewStageOf(?string $status): ?array
    {
        foreach (raha('workflow.reviewStages', []) as $r) {
            if ($r['status'] === $status) {
                return $r;
            }
        }

        return null;
    }

    /** @return string[] */
    public static function stageRole(string $status): array
    {
        return raha("workflow.stageRole.$status", []) ?? [];
    }

    public static function statusGroups(): array
    {
        return raha('workflow.statusGroups', []);
    }

    public static function stageLabel(string $status): ?string
    {
        return raha("workflow.stageLabels.$status");
    }

    public static function currentStageLabel(string $status, string $stage): string
    {
        return self::REVIEW_DISPLAY_LABELS[$status] ?? $stage;
    }

    public static function slaHours(string $status): ?int
    {
        $h = raha("workflow.slaHours.$status");

        return is_numeric($h) ? (int) $h : null;
    }

    public static function beneficiaryStatusOf(string $status): string
    {
        return raha("workflow.beneficiaryStatusOf.$status", 'under_review');
    }
}
