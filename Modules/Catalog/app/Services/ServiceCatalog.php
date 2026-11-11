<?php

namespace Modules\Catalog\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Service;

/** Port of front catalog_service.ts serviceRepo. */
class ServiceCatalog
{
    public const MANAGE_DENIED = 'لا تملك صلاحية إدارة الخدمات.';

    /** Writable ServiceItem fields (front name => column). */
    private const FIELDS = [
        'name' => 'name', 'nameEn' => 'name_en', 'category' => 'category', 'description' => 'description', 'status' => 'status',
        'requiresMedicalReport' => 'requires_medical_report', 'requiresDocuments' => 'requires_documents', 'allowInternal' => 'allow_internal',
        'allowExternal' => 'allow_external', 'publicVisible' => 'public_visible', 'portalVisible' => 'portal_visible', 'sortOrder' => 'sort_order',
        'subservices' => 'subservices', 'requirements' => 'requirements', 'deliveryType' => 'delivery_type', 'defaultVisitCount' => 'default_visit_count',
    ];

    public function __construct(private readonly PermissionResolver $permissions) {}

    /** @return Collection<int, Service> */
    public function all(): Collection
    {
        return Service::query()->orderBy('id')->get();
    }

    /** Active services shown in the request form, in configured order (front serviceRepo.requestable). */
    public function requestable(): Collection
    {
        return Service::query()->where('status', 'active')->where('portal_visible', true)->get()
            ->sortBy(fn (Service $s) => [$s->sort_order ?? 99, $s->id])->values();
    }

    public function get(string $id): Service
    {
        return Service::query()->find($id) ?? throw DomainRuleException::notFound('الخدمة غير موجودة.');
    }

    /** Create (no $id) or update. $data holds only the keys the client sent (absent = keep, like the front spread). */
    public function save(array $data, User $user, ?string $id = null): Service
    {
        if (! $this->permissions->has($user, 'services.manage')) {
            throw DomainRuleException::forbidden(self::MANAGE_DENIED);
        }
        if ($bad = ServiceDelivery::validate($data)) {
            throw new DomainRuleException($bad);
        }
        if (array_key_exists('requirements', $data) && $data['requirements'] !== null) {
            $data['requirements'] = self::normalizeRequirements($data['requirements']);
        }
        if (isset($data['subservices'])) {
            $data['subservices'] = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $data['subservices']), fn ($x) => $x !== ''));
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $dup = Service::query()->where('name', $data['name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
            if ($dup) {
                throw new DomainRuleException('توجد خدمة بنفس الاسم.');
            }
        }
        $attrs = [];
        foreach (self::FIELDS as $key => $col) {
            if (array_key_exists($key, $data)) {
                $attrs[$col] = $data[$key];
            }
        }
        if (isset($attrs['default_visit_count'])) {
            $attrs['default_visit_count'] = (int) $attrs['default_visit_count'];
        }

        return DB::transaction(function () use ($attrs, $id, $user) {
            if ($id) {
                $service = Service::query()->lockForUpdate()->find($id) ?? throw DomainRuleException::notFound('الخدمة غير موجودة.');
                $merged = ServiceDelivery::sanitize(array_merge(['delivery_type' => $service->delivery_type], $attrs));
                if (($merged['delivery_type'] ?? null) === 'one_time') {
                    $attrs['default_visit_count'] = null;
                }
                $service->fill($attrs)->save();
                Activity::emit('تم تعديل خدمة', 'خدمة', $service->id, $user);

                return $service->refresh();
            }
            foreach (['name', 'category', 'description'] as $required) {
                if (! isset($attrs[$required])) {
                    throw new DomainRuleException('أكمل الحقول المطلوبة.');
                }
            }
            $attrs = ServiceDelivery::sanitize($attrs + ['status' => 'active']);
            $service = Service::query()->create(['id' => Sequence::serviceId()] + $attrs);
            Activity::emit('تم إنشاء خدمة', 'خدمة', $service->id, $user);

            return $service->refresh();
        });
    }

    public function toggle(string $id, User $user): Service
    {
        if (! $this->permissions->has($user, 'services.manage')) {
            throw DomainRuleException::forbidden(self::MANAGE_DENIED);
        }

        return DB::transaction(function () use ($id, $user) {
            $service = Service::query()->lockForUpdate()->find($id) ?? throw DomainRuleException::notFound('الخدمة غير موجودة.');
            $status = $service->status === 'active' ? 'inactive' : 'active';
            $service->update(['status' => $status]);
            Activity::emit($status === 'active' ? 'تم تفعيل خدمة' : 'تم إيقاف خدمة', 'خدمة', $id, $user);

            return $service->refresh();
        });
    }

    /** Coerces a ServiceRequirementConfig to its exact front shape. */
    public static function normalizeRequirements(array $r): array
    {
        $base = RequirementsEngine::noRequirements();
        $docs = [];
        foreach ($r['extraDocs'] ?? [] as $d) {
            $label = trim((string) ($d['label'] ?? ''));
            if ($label === '') {
                throw new DomainRuleException('أدخل اسم كل مستند إضافي أو احذفه.');
            }
            $doc = ['id' => (string) (($d['id'] ?? '') ?: Str::lower(Str::random(6))), 'label' => $label, 'required' => (bool) ($d['required'] ?? false), 'active' => (bool) ($d['active'] ?? true)];
            if (isset($d['explanation']) && trim((string) $d['explanation']) !== '') {
                $doc['explanation'] = trim((string) $d['explanation']);
            }
            $docs[] = $doc;
        }
        $out = [
            'medicalReport' => (bool) ($r['medicalReport'] ?? $base['medicalReport']), 'prescription' => (bool) ($r['prescription'] ?? $base['prescription']),
            'needDescription' => (bool) ($r['needDescription'] ?? $base['needDescription']),
            'reportMaxAgeDays' => max(1, min(365, (int) ($r['reportMaxAgeDays'] ?? $base['reportMaxAgeDays']))), 'extraDocs' => $docs,
        ];
        if (isset($r['note']) && trim((string) $r['note']) !== '') {
            $out['note'] = trim((string) $r['note']);
        }

        return $out;
    }

    /** Projection for the beneficiary request wizard (no internal flags). */
    public static function toRequestable(Service $s): array
    {
        $d = ServiceDelivery::normalize($s);

        return array_filter([
            'id' => $s->id, 'name' => $s->name, 'nameEn' => $s->name_en, 'category' => $s->category, 'description' => $s->description,
            'status' => $s->status, 'requiresMedicalReport' => $s->requires_medical_report, 'requiresDocuments' => $s->requires_documents,
            'portalVisible' => (bool) $s->portal_visible, 'sortOrder' => $s->sort_order, 'subservices' => $s->subservices,
            'requirements' => $s->requirements ?? RequirementsEngine::noRequirements(), 'deliveryType' => $d['deliveryType'],
            'defaultVisitCount' => $d['defaultVisitCount'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
