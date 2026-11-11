<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Upload;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\Requirements\RequirementsEngine;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploads and case documents (front document_service + case_document_service + caseService.reviewDocument).
 * Files live on the private disk; an Upload becomes a CaseDocument when attached (only by its uploader, once).
 */
class DocumentService
{
    public const REVIEW_LABELS = ['accepted' => 'مقبول', 'unclear' => 'غير واضح', 'expired' => 'منتهي الصلاحية', 'replace' => 'مطلوب استبداله'];

    public function __construct(private PermissionResolver $perms, private CaseAccess $access, private CaseRecorder $recorder) {}

    public static function disk(): string
    {
        return config('raha.uploads.disk', 'local');
    }

    /** Stores a file uploaded by any account. Type is checked on the real (sniffed) MIME, not the client header. */
    public function storeUpload(UploadedFile $file, User $user): Upload
    {
        $cfg = config('raha.uploads');
        $mime = (string) $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());
        if (! isset($cfg['mimes'][$mime]) || ! in_array($ext, $cfg['extensions'], true)) {
            throw new DomainRuleException('نوع الملف غير مدعوم. يرجى اختيار PDF أو صورة بصيغة JPG أو PNG.');
        }
        if ($file->getSize() > $cfg['max_kb'] * 1024) {
            throw new DomainRuleException('حجم الملف أكبر من الحد المسموح ('.raha('document_rules.uploadRules.maxLabel', '٥ ميجابايت').').');
        }
        $path = $file->store('uploads', self::disk());

        return Upload::query()->create([
            'user_id' => $user->id, 'name' => mb_substr($file->getClientOriginalName(), 0, 250), 'mime' => $mime, 'type' => $cfg['mimes'][$mime],
            'size' => $file->getSize(), 'path' => $path,
        ]);
    }

    /** Loads an upload the user may attach (own, not yet attached). */
    public function attachableUpload(string $uploadId, User $user): Upload
    {
        $u = Upload::query()->lockForUpdate()->find($uploadId);
        if (! $u || $u->user_id !== $user->id || $u->attached_at !== null) {
            throw new DomainRuleException('الملف المرفق غير صالح أو تم استخدامه مسبقًا.');
        }

        return $u;
    }

    public static function labelFor(?string $docType, ?string $fallback = null): string
    {
        return $fallback ?: (RequirementsEngine::docTypes()[$docType ?? '']['label'] ?? 'مستند إضافي');
    }

    /** Creates the CaseDocument for an upload and marks the upload attached. */
    public function attach(RahaCase $c, Upload $u, User $user, array $meta): CaseDocument
    {
        $doc = CaseDocument::query()->create([
            'id' => 'doc-'.Str::lower((string) Str::ulid()), 'case_id' => $c->id, 'name' => $u->name, 'category' => $meta['category'],
            'type' => $u->type, 'size' => $u->size, 'uploaded_by' => $meta['uploadedBy'] ?? $user->name, 'uploaded_by_id' => $user->id,
            'visibility' => $meta['visibility'] ?? 'both', 'at' => $meta['at'] ?? now(), 'doc_type' => $meta['docType'] ?? null,
            'report_date' => $meta['reportDate'] ?? null, 'mime' => $u->mime, 'path' => $u->path,
        ]);
        $u->forceFill(['attached_at' => now()])->save();
        $c->unsetRelation('documents');

        return $doc;
    }

    private function editable(string $caseId, User $user, string $permission): RahaCase
    {
        $c = RahaCase::query()->lockForUpdate()->find($caseId);
        if (! $c || ! $this->access->canAccessCase($c->load('tasks'), $user) || ! $this->perms->has($user, $permission)) {
            throw DomainRuleException::forbidden('لا تملك صلاحية تنفيذ هذا الإجراء.');
        }
        CaseAccess::assertMutable($c);

        return $c;
    }

    /** Staff document upload (front caseDocumentService.upload; staff uploads default to internal). */
    public function staffUpload(string $caseId, User $user, array $data): CaseDocument
    {
        return DB::transaction(function () use ($caseId, $user, $data) {
            $c = $this->editable($caseId, $user, 'beneficiaries.documents.upload');
            $u = $this->attachableUpload($data['uploadId'], $user);
            $doc = $this->attach($c, $u, $user, ['category' => $data['category'], 'docType' => $data['docType'] ?? null, 'visibility' => $data['visibility'] ?? 'internal']);
            $this->recorder->addActivity($c, "أضاف {$user->name} المستند «{$doc->category}»", $user->name, 'internal', ['actorId' => $user->id]);
            Activity::audit('رفع مستند', 'طلب', $c->id, $user, ['documentId' => $doc->id]);

            return $doc;
        });
    }

    /** Gap fix: the mock removed silently; deletion is now recorded in history and audit. The file is kept (superseded). */
    public function remove(string $caseId, string $docId, User $user): void
    {
        DB::transaction(function () use ($caseId, $docId, $user) {
            $c = $this->editable($caseId, $user, 'beneficiaries.documents.delete');
            $doc = CaseDocument::query()->where('case_id', $c->id)->where('superseded', false)->find($docId);
            if (! $doc) {
                throw DomainRuleException::notFound('المستند غير موجود.');
            }
            $doc->forceFill(['superseded' => true])->save();
            $this->recorder->addActivity($c, "حذف {$user->name} المستند «{$doc->category}»", $user->name, 'internal', ['actorId' => $user->id]);
            Activity::audit('حذف مستند', 'طلب', $c->id, $user, ['documentId' => $doc->id, 'name' => $doc->name]);
        });
    }

    /** Marks one document; only the owner of the active review task (front reviewDocument). */
    public function review(string $caseId, string $docId, string $status, User $user): CaseDocument
    {
        if (! $this->perms->hasAny($user, ['cases.review', 'medical.review'])) {
            throw DomainRuleException::forbidden('ليست لديك صلاحية مراجعة المستندات.');
        }

        return DB::transaction(function () use ($caseId, $docId, $status, $user) {
            $c = RahaCase::query()->lockForUpdate()->find($caseId);
            $c?->load('tasks');
            $item = $c && $this->access->canAccessCase($c, $user) ? $c : null;
            if ($item) {
                CaseAccess::assertMutable($item);
            }
            if (! $item || ! $user->isStaff() || $item->activeTask()?->assigned_user_id !== $user->id) {
                throw DomainRuleException::forbidden('استلم مهمة المراجعة أولًا.');
            }
            $doc = CaseDocument::query()->where('case_id', $c->id)->where('superseded', false)->find($docId);
            if (! $doc) {
                throw DomainRuleException::notFound('المستند غير موجود.');
            }
            $doc->forceFill(['review' => ['status' => $status, 'by' => $user->name, 'at' => now_iso()]])->save();
            $label = self::REVIEW_LABELS[$status];
            $this->recorder->addActivity($c, "راجع {$user->name} المستند «{$doc->category}»: {$label}", $user->name, 'internal', ['actorId' => $user->id]);
            Activity::emit("مراجعة مستند: {$label}", 'طلب', $c->id, $user);

            return $doc;
        });
    }

    /** Authorized file download. Beneficiary: own non-internal docs; provider: provider-visible docs of own cases; staff: documents tab. */
    public function download(string $caseId, string $docId, User $user): StreamedResponse
    {
        $c = $this->access->findAccessible($caseId, $user);
        $doc = CaseDocument::query()->where('case_id', $c->id)->find($docId);
        $allowed = $doc && match (true) {
            $user->isBeneficiary() => $doc->visibility !== 'internal' && ! $doc->superseded,
            $user->isProvider() => ! $doc->superseded && CaseProjector::providerVisible($doc),
            default => $this->access->canAccessCaseTab($user, 'المستندات'),
        };
        if (! $allowed) {
            throw DomainRuleException::notFound('المستند غير موجود.');
        }
        if (! $doc->path || ! Storage::disk(self::disk())->exists($doc->path)) {
            throw DomainRuleException::notFound('الملف غير متوفر لهذا المستند.');
        }

        return Storage::disk(self::disk())->download($doc->path, $doc->name, ['Content-Type' => $doc->mime ?? 'application/octet-stream']);
    }
}
