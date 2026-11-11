<?php

namespace Modules\Audit\Http\Controllers;

use App\Exceptions\DomainRuleException;
use App\Models\AuditLog;
use App\Support\Permissions\PermissionResolver;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/** Audit log (front activity_service auditStore + audit_page filters). */
class AuditController extends Controller
{
    /** Record-scoped audit (detail pages) is readable with the view permission of that record's domain. */
    public const RECORD_PERMISSIONS = ['مقدم خدمة' => 'providers.view', 'تقييم مقدم خدمة' => 'providers.view', 'خدمة' => 'services.view', 'أمان' => 'users.view'];

    public function __construct(private readonly PermissionResolver $permissions) {}

    /** GET /audit-logs?recordType=&recordId=&userId=&q=&from=&to= — newest first. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'recordType' => ['nullable', 'string', 'max:64'], 'recordId' => ['nullable', 'string', 'max:64'], 'userId' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:120'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $this->authorizeRead($request, $f);
        $rows = AuditLog::query()
            ->when($f['recordType'] ?? null, fn ($q, $v) => $q->where('record_type', $v))
            ->when($f['recordId'] ?? null, fn ($q, $v) => $q->where('record_id', $v))
            ->when($f['userId'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('at', '<=', Carbon::parse($v)->endOfDay()))
            ->orderByDesc('at')->orderByDesc('id')->get()
            ->filter(fn (AuditLog $a) => Text::matches("{$a->user} {$a->action} {$a->record_id}", $f['q'] ?? null))
            ->values();

        return response()->json(['data' => $rows->map->toFront()->values()]);
    }

    private function authorizeRead(Request $request, array $f): void
    {
        $user = $request->user();
        if ($this->permissions->has($user, 'audit.view')) {
            return;
        }
        $type = $f['recordType'] ?? null;
        $scoped = $type && ! empty($f['recordId']) && isset(self::RECORD_PERMISSIONS[$type]) && $this->permissions->has($user, self::RECORD_PERMISSIONS[$type]);
        $ownUserRows = empty($type) && empty($f['recordId']) && ! empty($f['userId']) && $this->permissions->has($user, 'users.view');
        if (! $scoped && ! $ownUserRows) {
            throw DomainRuleException::forbidden('ليست لديك صلاحية تنفيذ هذا الإجراء.');
        }
    }
}
