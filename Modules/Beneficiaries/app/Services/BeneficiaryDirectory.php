<?php

namespace Modules\Beneficiaries\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Beneficiaries\Models\Beneficiary;
use Modules\Beneficiaries\Models\BeneficiaryActivity;
use Modules\Beneficiaries\Models\BeneficiaryNote;
use Modules\Cases\Models\CaseDocument;
use Modules\Cases\Models\RahaCase;

/**
 * Staff-side beneficiary files (port of front beneficiary_service.ts + authService.createProfile).
 * Scope: beneficiaries.view ALL → every beneficiary; ASSIGNED → only beneficiaries of cases where the user is the
 * assignee or holds a task, and only those cases feed the sub-views.
 */
class BeneficiaryDirectory
{
    /** Statuses that have no financial row yet (front financials). */
    private const NO_FINANCE = ['new', 'medical_review', 'social_review', 'rejected', 'returned'];

    public function __construct(private readonly PermissionResolver $permissions) {}

    public function canSeeSensitive(User $user): bool
    {
        return $this->permissions->has($user, 'beneficiaries.viewSensitive');
    }

    private function assignedOnly(User $user): bool
    {
        return $this->permissions->scopeOf($user, 'beneficiaries.view') !== 'ALL';
    }

    /** Cases visible to the user for beneficiary views. */
    public function caseQuery(User $user): Builder
    {
        $q = RahaCase::query();
        if ($this->assignedOnly($user)) {
            $q->where(fn ($w) => $w->where('assignee', $user->name)
                ->orWhereIn('id', DB::table('case_tasks')->where('assigned_user_id', $user->id)->select('case_id')));
        }

        return $q;
    }

    /** @return Collection<int, array> list rows: BeneficiaryRecord + requests + lastRequest */
    public function list(User $user): Collection
    {
        $this->permissions->require($user, 'beneficiaries.view');
        $cases = $this->caseQuery($user)->orderByDesc('created_at')->get(['id', 'beneficiary_id', 'date', 'created_at'])->groupBy('beneficiary_id');
        $q = Beneficiary::query()->orderByDesc('created_at')->orderBy('id');
        if ($this->assignedOnly($user)) {
            $q->whereIn('id', $cases->keys()->all());
        }
        $sensitive = $this->canSeeSensitive($user);

        return $q->get()->map(function (Beneficiary $b) use ($cases, $sensitive) {
            $mine = $cases->get($b->id, collect());

            return $b->toRecord($sensitive) + ['requests' => $mine->count(), 'lastRequest' => $mine->first()?->date ?? '—'];
        })->values();
    }

    /** Loads a beneficiary the user may open (404 unknown, 403 outside ASSIGNED scope). */
    public function find(string $id, User $user): Beneficiary
    {
        $this->permissions->require($user, 'beneficiaries.view');
        $b = Beneficiary::query()->find($id) ?? throw DomainRuleException::notFound('المستفيد غير موجود.');
        if ($this->assignedOnly($user) && ! $this->caseQuery($user)->where('beneficiary_id', $id)->exists()) {
            throw DomainRuleException::forbidden('ليست لديك صلاحية عرض ملفات المستفيدين.');
        }

        return $b;
    }

    public function record(Beneficiary $b, User $user): array
    {
        return $b->toRecord($this->canSeeSensitive($user));
    }

    /** Staff-side creation (front AddBeneficiaryDialog → authService.createProfile). */
    public function create(array $data, User $user): Beneficiary
    {
        $this->permissions->require($user, 'beneficiaries.create');

        return DB::transaction(function () use ($data, $user) {
            $nationalId = trim($data['nationalId']);
            if (Beneficiary::query()->where('national_id', $nationalId)->lockForUpdate()->exists()) {
                throw new DomainRuleException('يوجد مستفيد مسجل بهذا الرقم.');
            }
            $b = Beneficiary::query()->create([
                'id' => Sequence::beneficiaryId(), 'first_name' => trim($data['firstName']), 'father_name' => trim($data['fatherName'] ?? ''),
                'grand_name' => trim($data['grandName'] ?? ''), 'family_name' => trim($data['familyName']), 'national_id' => $nationalId,
                'id_type' => $data['idType'] ?? (str_starts_with($nationalId, '2') ? 'إقامة' : 'هوية وطنية'), 'birth_date' => (string) ($data['birthDate'] ?? ''),
                'gender' => $data['gender'] ?? 'ذكر', 'nationality' => $data['nationality'] ?? 'سعودي', 'mobile' => (string) $data['mobile'],
                'email' => (string) ($data['email'] ?? ''), 'city' => trim($data['city']), 'district' => (string) ($data['district'] ?? ''),
                'address' => (string) ($data['address'] ?? ''), 'status' => 'active', 'has_portal_account' => false,
            ]);
            Activity::emit('تم إنشاء مستفيد', 'مستفيد', $b->id, $user);

            return $b->refresh();
        });
    }

    /** Front beneficiaryService.update. $changes uses BeneficiaryRecord keys (only the sent ones). */
    public function update(string $id, array $changes, User $user): Beneficiary
    {
        $this->permissions->require($user, 'beneficiaries.edit');
        if (array_key_exists('nationalId', $changes) && ! $this->canSeeSensitive($user)) {
            throw DomainRuleException::forbidden('تعديل رقم الهوية يتطلب صلاحية أعلى.');
        }
        $this->find($id, $user);

        return DB::transaction(function () use ($id, $changes, $user) {
            $b = Beneficiary::query()->lockForUpdate()->findOrFail($id);
            $current = $b->toRecord(true);
            $changed = array_keys(array_filter($changes, fn ($v, $k) => (string) $v !== (string) ($current[$k] ?? ''), ARRAY_FILTER_USE_BOTH));

            if (in_array('nationalId', $changed, true)) {
                $nid = trim($changes['nationalId']);
                if (Beneficiary::query()->where('national_id', $nid)->where('id', '!=', $id)->exists()
                    || DB::table('users')->where('national_id', $nid)->where(fn ($q) => $q->whereNull('beneficiary_id')->orWhere('beneficiary_id', '!=', $id))->exists()) {
                    throw new DomainRuleException('يوجد مستفيد مسجل بهذا الرقم.');
                }
                $b->national_id = $nid;
            }
            if (in_array('name', $changed, true)) {
                $parts = preg_split('/\s+/u', trim($changes['name']));
                // first [father [grand]] family — a single word keeps the stored family name.
                $n = count($parts);
                $b->first_name = $parts[0];
                $b->father_name = $n >= 3 ? $parts[1] : '';
                $b->grand_name = $n >= 4 ? $parts[2] : '';
                if ($n >= 2) {
                    $b->family_name = implode(' ', array_slice($parts, match ($n) { 2 => 1, 3 => 2, default => 3 }));
                }
            }
            $map = ['mobile' => 'mobile', 'email' => 'email', 'nationality' => 'nationality', 'city' => 'city', 'district' => 'district',
                'address' => 'address', 'birthDate' => 'birth_date', 'gender' => 'gender', 'status' => 'status'];
            foreach ($map as $key => $col) {
                if (in_array($key, $changed, true)) {
                    $b->{$col} = (string) $changes[$key];
                }
            }
            $b->save();

            if (in_array('name', $changed, true)) {
                RahaCase::query()->where('beneficiary_id', $id)->update(['beneficiary' => $b->fullName()]);
            }
            if ($account = $b->account()->first()) {
                $second = $b->father_name ?: $b->family_name;
                $account->forceFill([
                    'name' => trim("{$b->first_name} {$second}"), 'initials' => mb_substr($b->first_name, 0, 1).mb_substr($second, 0, 1),
                    'mobile' => $b->mobile, 'national_id' => $b->national_id,
                ])->save();
            }
            $count = count($changed);
            $this->log($id, $user, "تم تحديث بيانات المستفيد ({$count} حقول)", 'account');
            Activity::audit('تم تحديث بيانات مستفيد', 'مستفيد', $id, $user, ['fields' => $changed]);

            return $b->refresh();
        });
    }

    /** Request summaries (newest first). */
    public function requests(Beneficiary $b, User $user): array
    {
        $medical = $this->permissions->has($user, 'beneficiaries.medical.view');
        $docs = $this->permissions->has($user, 'beneficiaries.documents.view');
        $cases = $this->caseQuery($user)->where('beneficiary_id', $b->id)->with('documents')->orderByDesc('created_at')->get();

        return $cases->map(fn (RahaCase $c) => array_filter([
            'id' => $c->id, 'beneficiaryId' => $c->beneficiary_id, 'beneficiary' => $c->beneficiary, 'service' => $c->service, 'serviceId' => $c->service_id,
            'subservice' => $c->subservice, 'status' => $c->status, 'stage' => $c->stage, 'assignee' => $c->assignee, 'date' => $c->date, 'updated' => $c->updated,
            'createdAt' => iso($c->created_at), 'updatedAt' => iso($c->updated_at), 'city' => $c->city, 'priority' => $c->priority,
            'provider' => $c->provider, 'providerId' => $c->provider_id, 'appointment' => $c->appointment, 'serviceConfirmed' => $c->service_confirmed,
            'evaluation' => $c->evaluation, 'requestFor' => $c->request_for,
            'medical' => $medical ? $c->medical : null,
            'documents' => $docs ? $c->documents->map(fn (CaseDocument $d) => $this->document($d, $c->id))->values()->all() : [],
        ], fn ($v) => $v !== null))->values()->all();
    }

    /** Flattened case documents (front BeneficiaryDocument). */
    public function documents(Beneficiary $b, User $user): array
    {
        $this->permissions->require($user, 'beneficiaries.documents.view');
        $ids = $this->caseQuery($user)->where('beneficiary_id', $b->id)->orderByDesc('created_at')->pluck('id');

        return CaseDocument::query()->whereIn('case_id', $ids)->where('superseded', false)->orderByDesc('at')->get()
            ->map(fn (CaseDocument $d) => $this->document($d, $d->case_id))->values()->all();
    }

    private function document(CaseDocument $d, string $caseId): array
    {
        return [
            'id' => $d->id, 'name' => $d->name, 'category' => $d->category === 'التقرير الطبي' ? 'التقارير الطبية' : $d->category, 'caseId' => $caseId,
            'at' => iso($d->at), 'uploadedBy' => $d->uploaded_by, 'size' => (int) $d->size, 'type' => $d->type, 'hasFile' => $d->path !== null,
        ];
    }

    /** Front beneficiaryService.financials (demo pricing/coverage from raha('beneficiary_records.*')). */
    public function financials(Beneficiary $b, User $user): array
    {
        $this->permissions->require($user, 'beneficiaries.financial.view');
        $pricing = raha('beneficiary_records.servicePricing', []);
        $coverage = (float) raha('beneficiary_records.defaultCoverage', 0.8);

        return $this->caseQuery($user)->where('beneficiary_id', $b->id)->whereNotIn('status', self::NO_FINANCE)->orderByDesc('created_at')->get()
            ->map(function (RahaCase $c) use ($pricing, $coverage) {
                $approved = (int) ($pricing[$c->service] ?? 1000);
                $charity = (int) round($approved * $coverage);
                $ben = $approved - $charity;
                $paid = in_array($c->status, ['archived', 'finance_review'], true) ? $ben : 0;

                return ['caseId' => $c->id, 'service' => $c->service, 'approved' => $approved, 'charity' => $charity, 'beneficiary' => $ben,
                    'paid' => $paid, 'paymentStatus' => $paid >= $ben ? 'تم السداد' : 'بانتظار السداد'];
            })->values()->all();
    }

    public function notes(Beneficiary $b, User $user): array
    {
        $this->permissions->require($user, 'beneficiaries.notes.view');

        return $b->notes()->get()->map(fn (BeneficiaryNote $n) => $this->note($n))->values()->all();
    }

    public function addNote(Beneficiary $b, User $user, string $text, string $visibility): array
    {
        if (! $this->permissions->has($user, 'beneficiaries.notes.create')) {
            throw DomainRuleException::forbidden('ليست لديك صلاحية إضافة ملاحظة.');
        }
        $note = BeneficiaryNote::query()->create([
            'beneficiary_id' => $b->id, 'at' => now(), 'author' => $user->name, 'author_id' => $user->id, 'role' => (string) $user->role_label,
            'text' => $text, 'visibility' => $visibility,
        ]);

        return $this->note($note->refresh());
    }

    private function note(BeneficiaryNote $n): array
    {
        return ['id' => $n->id, 'beneficiaryId' => $n->beneficiary_id, 'at' => iso($n->at), 'author' => $n->author, 'role' => $n->role, 'text' => $n->text, 'visibility' => $n->visibility];
    }

    /**
     * Merged timeline (newest first): account log + case activities (text only) + one entry per note (the note text itself
     * stays behind beneficiaries.notes.view) + the account creation entry.
     */
    public function activity(Beneficiary $b, User $user): array
    {
        $this->permissions->require($user, 'beneficiaries.activity.view');
        $items = BeneficiaryActivity::query()->where('beneficiary_id', $b->id)->get()->map(fn (BeneficiaryActivity $a) => array_filter([
            'id' => $a->id, 'at' => iso($a->at), 'actor' => $a->actor, 'role' => $a->role, 'text' => $a->text, 'caseId' => $a->case_id, 'kind' => $a->kind, 'beneficiaryId' => $b->id,
        ], fn ($v) => $v !== null));
        $caseIds = $this->caseQuery($user)->where('beneficiary_id', $b->id)->pluck('id');
        $fromCases = DB::table('case_activities')->whereIn('case_id', $caseIds)->get(['id', 'case_id', 'at', 'actor', 'text'])->map(fn ($a) => [
            'id' => "{$a->case_id}-{$a->id}", 'at' => iso($a->at), 'actor' => $a->actor, 'role' => str_contains($a->actor, 'المستفيد') ? 'المستفيد' : 'موظف',
            'text' => $a->text, 'caseId' => $a->case_id, 'kind' => 'request',
        ]);
        $notes = $b->notes()->get()->map(fn (BeneficiaryNote $n) => [
            'id' => "note-{$n->id}", 'at' => iso($n->at), 'actor' => $n->author, 'role' => $n->role, 'text' => 'تمت إضافة ملاحظة على ملف المستفيد', 'kind' => 'note', 'beneficiaryId' => $b->id,
        ]);
        $created = $b->created_at ? [['id' => 'created', 'at' => iso($b->created_at), 'actor' => 'النظام', 'role' => 'النظام', 'text' => 'تم إنشاء حساب المستفيد', 'kind' => 'account']] : [];

        return collect($items)->concat($fromCases)->concat($notes)->concat($created)->sortByDesc('at')->values()->all();
    }

    private function log(string $beneficiaryId, User $user, string $text, string $kind, ?string $caseId = null): void
    {
        BeneficiaryActivity::query()->create([
            'beneficiary_id' => $beneficiaryId, 'at' => now(), 'actor' => $user->name, 'role' => (string) $user->role_label, 'text' => $text, 'kind' => $kind, 'case_id' => $caseId,
        ]);
    }
}
