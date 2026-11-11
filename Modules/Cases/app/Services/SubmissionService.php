<?php

namespace Modules\Cases\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Modules\Beneficiaries\Models\Beneficiary;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\RahaCase;
use Modules\Cases\Services\Requirements\RequirementsEngine;
use Modules\Catalog\Models\Service;

/** New requests and corrections (port of front caseService.submit / resubmit + recipient_service.buildRecipientRequest). */
class SubmissionService
{
    public const NEEDS_SURVEY = 'يرجى تقييم طلبك السابق أولًا';

    public function __construct(
        private PermissionResolver $perms, private CaseAccess $access, private CaseRecorder $recorder, private TaskService $tasks,
        private ReviewValidator $validator, private DocumentService $documents, private FastTrackService $fastTrack,
    ) {}

    /** Hidden-field-aware social values (front visibleValues(socialFields, …)). */
    public static function visibleSocial(array $values): array
    {
        $out = [];
        foreach (raha('request_form.socialFields', []) as $f) {
            $when = $f['visibleWhen'] ?? null;
            $visible = ! $when || in_array((string) ($values[$when['key']] ?? ''), $when['in'], true);
            $v = $values[$f['key']] ?? null;
            if ($visible && is_scalar($v) && (string) $v !== '') {
                $out[$f['key']] = (string) $v;
            }
        }

        return $out;
    }

    /** front buildRecipientRequest: account owner vs. service recipient. */
    public static function buildRecipientRequest(User $user, string $requestFor, string $relationship, array $patient, array $social): array
    {
        $owner = $user->beneficiary_id ?? $user->id;
        $p = fn (string $k) => is_scalar($patient[$k] ?? null) ? (string) $patient[$k] : '';
        $recipient = [
            'id' => $requestFor === 'self' ? $owner : 'PAT-'.strtoupper(substr(base_convert((string) (int) (microtime(true) * 1000), 10, 36), -6)),
            'accountOwnerId' => $owner, 'relationshipToAccountOwner' => $requestFor === 'self' ? 'نفسه' : $relationship,
            'fullName' => trim($p('fullName')), 'dateOfBirth' => $p('birthDate'), 'gender' => $p('gender'), 'nationality' => $p('nationality'),
            'maritalStatus' => $p('maritalStatus'), 'identificationType' => $p('idType'), 'identificationNumber' => $p('idNumber'), 'mobileNumber' => $p('mobile'),
        ];
        $s = self::visibleSocial($social);

        return [
            'requestFor' => $requestFor, 'accountOwnerId' => $owner, 'accountOwnerName' => $user->name, 'recipient' => $recipient, 'social' => $s,
            'applicant' => [
                'name' => $recipient['fullName'], 'nationalId' => $recipient['identificationNumber'], 'mobile' => $recipient['mobileNumber'], 'birthDate' => $recipient['dateOfBirth'],
                'gender' => $recipient['gender'], 'nationality' => $recipient['nationality'], 'city' => $s['city'] ?? '', 'district' => $s['district'] ?? '',
            ],
        ];
    }

    public function pendingSurvey(User $user): ?RahaCase
    {
        if (! $user->beneficiary_id) {
            return null;
        }

        return RahaCase::query()->where('beneficiary_id', $user->beneficiary_id)->where('survey_pending', true)->whereNull('request_survey')->orderByDesc('created_at')->first();
    }

    /**
     * @param  array{serviceId: string, subservice?: string, description?: string, previouslyReceived?: string, medical?: array, need?: string,
     *   requestFor?: string, relationship?: string, patient?: array, social?: array, documents?: list<array{uploadId: string, docType?: string, reportDate?: string, label?: string}>}  $data
     */
    public function submit(User $user, array $data, ?string $beneficiaryId = null): RahaCase
    {
        $staff = ! $user->isBeneficiary();
        if ($staff) {
            $this->perms->require($user, 'beneficiaries.createRequest');
        } else {
            $this->perms->require($user, 'beneficiary.requests.create');
            if ($pending = $this->pendingSurvey($user)) {
                throw new DomainRuleException(self::NEEDS_SURVEY." ({$pending->id}).");
            }
        }
        $owner = $beneficiaryId ?? $user->beneficiary_id;
        if (! $owner) {
            throw new DomainRuleException('حدد المستفيد من الطلب.');
        }
        $record = Beneficiary::query()->find($owner);
        if ($staff && ! $record) {
            throw DomainRuleException::notFound('المستفيد غير موجود.');
        }
        $svc = Service::query()->find($data['serviceId'] ?? '');
        if (! $svc || $svc->status !== 'active') {
            throw new DomainRuleException('الخدمة المختارة غير متاحة.');
        }

        return DB::transaction(function () use ($user, $data, $staff, $owner, $record, $svc) {
            $t = now();
            $extra = [];
            if (! $staff && ! empty($data['patient'])) {
                $extra = self::buildRecipientRequest($user, ($data['requestFor'] ?? 'self') === 'family' ? 'family' : 'self', (string) ($data['relationship'] ?? ''), (array) $data['patient'], (array) ($data['social'] ?? []));
            } elseif ($staff) {
                $extra['applicant'] = array_filter([
                    'name' => $record->fullName(), 'nationalId' => $record->national_id, 'mobile' => $record->mobile, 'birthDate' => $record->birth_date, 'gender' => $record->gender,
                    'nationality' => $record->nationality, 'city' => $record->city, 'district' => $record->district, 'address' => $record->address,
                ], fn ($v) => $v !== null && $v !== '');
            } else {
                $extra['social'] = self::visibleSocial((array) ($data['social'] ?? []));
            }
            $social = $extra['social'] ?? [];

            // Uploads → document drafts (only the uploader may attach, each upload once).
            $drafts = [];
            foreach ((array) ($data['documents'] ?? []) as $d) {
                $u = $this->documents->attachableUpload((string) ($d['uploadId'] ?? ''), $user);
                $drafts[] = ['upload' => $u, 'docType' => $d['docType'] ?? null, 'reportDate' => $d['reportDate'] ?? null, 'label' => $d['label'] ?? null, 'visibility' => 'both', 'at' => iso($t)];
            }
            $results = RequirementsEngine::evaluate($svc, $social, $drafts);
            $known = array_column($results, 'docType');
            // Documents whose condition no longer applies are not sent (front notRequiredDocs).
            $drafts = array_values(array_filter($drafts, fn ($d) => ! $d['docType'] || $d['docType'] === 'additional' || in_array($d['docType'], $known, true)));
            foreach ($drafts as &$d) {
                $label = collect($results)->firstWhere('docType', $d['docType'])['label'] ?? null;
                $d['category'] = DocumentService::labelFor($d['docType'], $d['label'] ?: $label);
            }
            unset($d);

            $medical = array_filter((array) ($data['medical'] ?? []), fn ($v) => is_scalar($v));
            $medical = array_map('strval', $medical);
            $report = collect($drafts)->firstWhere('docType', 'medical_report')['reportDate'] ?? null;
            if ($report) {
                $medical['reportDate'] = $report;
            }
            if (! empty($data['need'])) {
                $medical['need'] = (string) $data['need'];
            }
            $applicant = $extra['applicant'] ?? [];
            [$stage, $assignee] = WorkflowConfig::stageFor('new');
            $id = Sequence::caseId();
            $c = new RahaCase;
            $c->forceFill([
                'id' => $id, 'beneficiary_id' => $owner, 'workflow_id' => 'phase1-medical', 'beneficiary' => ($applicant['name'] ?? '') ?: $user->name,
                'national_id' => $applicant['nationalId'] ?? '', 'mobile' => $applicant['mobile'] ?? null, 'city' => $applicant['city'] ?? '',
                'service' => $svc->name, 'service_id' => $svc->id, 'subservice' => ($data['subservice'] ?? null) ?: null, 'description' => $data['description'] ?? null,
                'previously_received' => $data['previouslyReceived'] ?? null, 'medical' => $medical, 'applicant' => $applicant ?: null, 'social' => $extra['social'] ?? null,
                'recipient' => $extra['recipient'] ?? null, 'request_for' => $extra['requestFor'] ?? null, 'account_owner_id' => $extra['accountOwnerId'] ?? null,
                'account_owner_name' => $extra['accountOwnerName'] ?? null, 'requirements' => RequirementsEngine::snapshot($results),
                'visit_plan' => VisitGuard::snapshotVisitPlan($svc, $svc->name, iso($t)), 'date' => ar_date($t), 'updated' => ar_date($t),
                'stage' => $stage, 'assignee' => $assignee, 'status' => 'new', 'survey_pending' => $staff ? null : true, 'created_at' => $t, 'updated_at' => $t,
            ]);

            $ft = $staff ? null : $this->fastTrack->checkEligibility($user);
            if ($ft && $ft['eligible']) {
                [$ftStage, $ftAssignee] = WorkflowConfig::stageFor($ft['entryStage']);
                $c->forceFill(['status' => $ft['entryStage'], 'stage' => $ftStage, 'assignee' => $ftAssignee,
                    'fast_track' => ['previousRequestId' => $ft['previous']->id, 'reason' => $ft['reason'], 'skippedStages' => $ft['skippedStages'], 'decidedAt' => iso($t)]]);
            }
            $docArrays = array_map(fn ($d) => array_diff_key($d, ['upload' => 1]), $drafts);
            if ($housing = RequirementsEngine::housingDocumentErrors($social, $docArrays)) {
                throw new DomainRuleException($housing[0]);
            }
            $c->setRelation('tasks', collect());
            $this->validator->assertDocuments($c, $docArrays, null, $social);
            $c->save();

            foreach ($drafts as $d) {
                $this->documents->attach($c, $d['upload'], $user, ['category' => $d['category'], 'docType' => $d['docType'], 'reportDate' => $d['reportDate'], 'visibility' => 'both', 'at' => $t, 'uploadedBy' => $staff ? $user->name : $c->beneficiary]);
            }
            $this->recorder->addActivity($c, $staff ? "تم إنشاء الطلب نيابة عن المستفيد بواسطة {$user->name}" : 'تم استلام طلبك بنجاح.', $staff ? $user->name : 'المستفيد عبر البوابة', 'both', ['at' => $t, 'actorId' => $user->id]);
            if ($ft && $ft['eligible']) {
                $skipped = $ft['skippedStages'] ? ' المراحل المتخطاة: '.implode('، ', $ft['skippedStages']).'.' : '';
                $this->recorder->addActivity($c, "طلب متكرر — مسار سريع: {$ft['reason']}{$skipped}", 'النظام', 'internal', ['action' => 'fast_track', 'toStage' => $ft['entryStage'], 'at' => $t]);
            } else {
                $this->recorder->addActivity($c, 'تم إسناد الطلب للتدقيق', 'النظام', 'internal', ['action' => 'submit', 'toStage' => 'new', 'at' => $t]);
            }
            $c->unsetRelation('tasks');
            $this->tasks->withCurrentTask($c, $t);
            $this->recorder->caseEvent($c, 'تم إنشاء طلب', $user);

            return $c->fresh();
        });
    }

    /**
     * @param  array{description?: string, recipient?: array, medical?: array, social?: array, keepDocIds?: string[], documents?: list<array{uploadId: string, docType?: string, reportDate?: string, label?: string}>}  $changes
     */
    public function resubmit(string $id, User $user, array $changes): RahaCase
    {
        return DB::transaction(function () use ($id, $user, $changes) {
            $c = RahaCase::query()->lockForUpdate()->with(['tasks', 'documents'])->find($id);
            if (! $c || ! $this->access->canAccessCase($c, $user) || $c->status !== 'returned' || ! $c->return_info) {
                throw new DomainRuleException('لا يمكن تعديل هذا الطلب حاليًا.');
            }
            if (! $user->isBeneficiary()) {
                throw DomainRuleException::forbidden('إعادة الإرسال متاحة لصاحب حساب المستفيد فقط.');
            }
            $this->perms->require($user, 'beneficiary.requests.editReturned');
            $info = $c->return_info;
            $now = now();
            $keep = array_key_exists('keepDocIds', $changes) && is_array($changes['keepDocIds']) ? $changes['keepDocIds'] : null;
            $existing = $c->documents;
            $kept = $existing->filter(fn (CaseDocument $d) => $d->visibility === 'internal' || $keep === null || in_array($d->id, $keep, true));
            $drafts = [];
            foreach ((array) ($changes['documents'] ?? []) as $d) {
                $u = $this->documents->attachableUpload((string) ($d['uploadId'] ?? ''), $user);
                $docType = $d['docType'] ?? null;
                $label = collect($c->requirements ?? [])->firstWhere('docType', $docType)['label'] ?? null;
                $drafts[] = ['upload' => $u, 'docType' => $docType, 'reportDate' => $d['reportDate'] ?? null, 'category' => DocumentService::labelFor($docType, ($d['label'] ?? null) ?: $label), 'visibility' => 'both', 'at' => iso($now)];
            }
            $documents = array_merge(ReviewValidator::docArrays($kept), array_map(fn ($d) => array_diff_key($d, ['upload' => 1]), $drafts));
            $social = array_key_exists('social', $changes) && is_array($changes['social']) ? self::visibleSocial($changes['social']) : null;
            $this->validator->assertDocuments($c, $documents, $info['date'], $social ?? $c->social ?? []);
            $flagged = $existing->filter(fn (CaseDocument $d) => in_array($d->id, $info['docIds'] ?? [], true) || ($d->review && ($d->review['status'] ?? null) !== 'accepted'));
            foreach ($flagged as $old) {
                $replaced = collect($documents)->contains(fn ($d) => ($old->doc_type ? ($d['docType'] ?? null) === $old->doc_type : ($d['category'] ?? null) === $old->category)
                    && ($d['at'] ?? '') > $info['date'] && empty($d['review']));
                if (! $replaced) {
                    throw new DomainRuleException('يرجى استبدال المستندات المحددة للتصحيح.');
                }
            }
            $description = isset($changes['description']) ? (string) $changes['description'] : null;
            $medical = isset($changes['medical']) && is_array($changes['medical']) ? array_map('strval', array_filter($changes['medical'], 'is_scalar')) : null;
            $recipient = null;
            if (isset($changes['recipient']) && is_array($changes['recipient']) && $c->recipient) {
                $editable = ['fullName', 'dateOfBirth', 'gender', 'nationality', 'maritalStatus', 'identificationType', 'identificationNumber', 'mobileNumber'];
                $recipient = array_merge($c->recipient, array_map('strval', array_intersect_key(array_filter($changes['recipient'], 'is_scalar'), array_flip($editable))));
                if ($recipient == $c->recipient) {
                    $recipient = null;
                }
            }
            $comparisons = [
                'وصف الحالة' => $description !== null && trim($description) !== '' && $description !== $c->description,
                'بيانات المستفيد' => $recipient !== null,
                'المعلومات الطبية' => $medical !== null && $medical != ($c->medical ?? []),
                'البيانات الاجتماعية' => $social !== null && $social != ($c->social ?? []),
            ];
            foreach ($info['fields'] ?? [] as $f) {
                if (array_key_exists($f, $comparisons) && ! $comparisons[$f]) {
                    throw new DomainRuleException('يرجى تصحيح البيانات المحددة في سبب الإرجاع.');
                }
            }

            // Apply: removed documents are superseded (file kept for audit), new uploads attached.
            $removed = $existing->reject(fn (CaseDocument $d) => $kept->contains('id', $d->id));
            foreach ($removed as $d) {
                $d->forceFill(['superseded' => true])->save();
            }
            $this->recorder->touch($c, $info['fromStatus']);
            $c->forceFill([
                'description' => $description ?? $c->description, 'medical' => $medical ?? $c->medical, 'social' => $social ?? $c->social,
                'return_info' => null, 'return_history' => array_merge($c->return_history ?? [], [$info + ['resubmittedAt' => iso($now)]]),
            ]);
            if ($recipient) {
                $c->forceFill(['recipient' => $recipient, 'beneficiary' => $recipient['fullName'], 'national_id' => $recipient['identificationNumber'], 'mobile' => $recipient['mobileNumber']]);
            }
            $c->save();
            foreach ($drafts as $d) {
                $this->documents->attach($c, $d['upload'], $user, ['category' => $d['category'], 'docType' => $d['docType'], 'reportDate' => $d['reportDate'], 'visibility' => 'both', 'at' => $now]);
            }
            if ($recipient) {
                $this->recorder->addActivity($c, 'تم تصحيح بيانات المستفيد من الخدمة دون تغيير صاحب الحساب.', $user->name, 'both', ['at' => $now, 'actorId' => $user->id]);
            }
            $this->recorder->addActivity($c, 'قام المستفيد بتعديل الطلب وإعادة إرساله.', $user->name, 'both', ['action' => 'resubmit', 'fromStage' => 'returned', 'toStage' => $info['fromStatus'], 'at' => $now, 'actorId' => $user->id]);
            foreach ($drafts as $d) {
                $this->recorder->addActivity($c, "تم استبدال/إضافة المستند «{$d['category']}» عند إعادة الإرسال.", $user->name, 'both', ['at' => $now, 'actorId' => $user->id]);
            }
            $c->unsetRelation('tasks');
            $this->tasks->withCurrentTask($c, $now);
            $this->recorder->caseEvent($c, 'أعيد إرسال طلب بعد التعديل', $user);

            return $c->fresh();
        });
    }
}
