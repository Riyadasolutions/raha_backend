<?php

namespace Modules\Catalog\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Activity\Activity;
use App\Support\Ids\Sequence;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Cases\Models\RahaCase;
use Modules\Catalog\Models\Provider;
use Modules\Catalog\Models\Service;

/** Port of front catalog_service.ts providerRepo. Provider accounts only ever see their own record. */
class ProviderCatalog
{
    private const FIELDS = ['name', 'type', 'license', 'registry', 'phone', 'email', 'city', 'district', 'address', 'contact', 'status', 'notes', 'logo'];

    public function __construct(private readonly PermissionResolver $permissions) {}

    /** @return Collection<int, Provider> */
    public function visibleTo(User $user): Collection
    {
        $this->permissions->require($user, 'providers.view');
        $q = Provider::query()->with(['services', 'internalNotes'])->orderBy('id');
        if (! $user->isStaff()) {
            $q->where('id', $user->provider_id ?? '__none__');
        }

        return $q->get();
    }

    public function get(string $id, User $user): Provider
    {
        $this->permissions->require($user, 'providers.view');
        $p = Provider::query()->with(['services', 'internalNotes'])->find($id) ?? throw DomainRuleException::notFound('مقدم الخدمة غير موجود.');
        if (! $user->isStaff() && $user->provider_id !== $p->id) {
            throw DomainRuleException::forbidden();
        }

        return $p;
    }

    public function serialize(Provider $p, User $user): array
    {
        $out = $p->toFront($user->isStaff());
        $names = $p->services->pluck('name', 'id');
        $out['services'] = array_map(fn ($s) => $s + ['serviceName' => $names[$s['serviceId']] ?? null], $out['services']);

        return $out;
    }

    /** Create (no $id) or update. $data uses front field names; `services` (optional) replaces the linked service list. */
    public function save(array $data, User $user, ?string $id = null): Provider
    {
        $this->requireManage($user);
        if (array_key_exists('logo', $data) && $data['logo'] !== null && $data['logo'] !== '' && ! ProviderBranding::isSafeLogo($data['logo'])) {
            throw new DomainRuleException('ملف الشعار غير صالح.');
        }
        if (($data['logo'] ?? null) === '') {
            $data['logo'] = null;
        }
        $attrs = array_intersect_key($data, array_flip(self::FIELDS));
        if (isset($attrs['contact'])) {
            $attrs['contact'] = ['name' => (string) ($attrs['contact']['name'] ?? ''), 'mobile' => (string) ($attrs['contact']['mobile'] ?? ''), 'email' => (string) ($attrs['contact']['email'] ?? '')];
        }
        foreach (['license', 'registry', 'phone', 'email', 'city', 'district', 'address'] as $k) {
            if (array_key_exists($k, $attrs)) {
                $attrs[$k] = (string) $attrs[$k];
            }
        }
        $links = null;
        if (array_key_exists('services', $data) && $data['services'] !== null) {
            $links = collect($data['services'])->mapWithKeys(fn ($s) => [(string) (is_array($s) ? ($s['serviceId'] ?? '') : $s) => is_array($s) ? ($s['addedAt'] ?? null) : null]);
            $this->assertServicesExist($links->keys()->all());
        }

        return DB::transaction(function () use ($attrs, $links, $id, $user) {
            if ($id) {
                $p = Provider::query()->lockForUpdate()->find($id) ?? throw DomainRuleException::notFound('مقدم الخدمة غير موجود.');
                $p->fill($attrs)->save();
                $action = 'تم تعديل مزود خدمة';
            } else {
                $p = Provider::query()->create(array_merge(['status' => 'active', 'contact' => ['name' => '', 'mobile' => '', 'email' => '']], $attrs, ['id' => Sequence::providerId()]));
                $action = 'تم إنشاء مزود خدمة';
            }
            if ($links !== null) {
                $existing = DB::table('provider_service')->where('provider_id', $p->id)->pluck('added_at', 'service_id');
                $sync = [];
                foreach ($links as $serviceId => $addedAt) {
                    $sync[$serviceId] = ['added_at' => $existing[$serviceId] ?? ($addedAt ? \Illuminate\Support\Carbon::parse($addedAt) : now())];
                }
                $p->services()->sync($sync);
            }
            Activity::emit($action, 'مقدم خدمة', $p->id, $user);

            return $p->refresh()->load(['services', 'internalNotes']);
        });
    }

    public function addService(string $id, string $serviceId, User $user): Provider
    {
        $this->requireManage($user);
        $this->assertServicesExist([$serviceId]);

        return $this->update($id, $user, 'تمت إضافة خدمة لمزود', function (Provider $p) use ($serviceId) {
            if (! $p->services()->where('services.id', $serviceId)->exists()) {
                $p->services()->attach($serviceId, ['added_at' => now()]);
            }
        });
    }

    public function removeService(string $id, string $serviceId, User $user): Provider
    {
        $this->requireManage($user);

        return $this->update($id, $user, 'تمت إزالة خدمة من مزود', fn (Provider $p) => $p->services()->detach($serviceId));
    }

    public function addNote(string $id, string $text, User $user): Provider
    {
        if (! $user->isStaff()) {
            throw DomainRuleException::forbidden();
        }
        $this->permissions->require($user, 'providers.view');

        return $this->update($id, $user, 'تمت إضافة ملاحظة لمزود', fn (Provider $p) => $p->internalNotes()->create([
            'at' => now(), 'text' => $text, 'author' => $user->name, 'author_id' => $user->id,
        ]));
    }

    /** Cases served by this provider (summary rows), limited by the viewer's cases.view scope. */
    public function cases(string $id, User $user): array
    {
        if (! $user->isStaff()) {
            throw DomainRuleException::forbidden();
        }
        $this->permissions->require($user, 'cases.view');
        Provider::query()->find($id) ?? throw DomainRuleException::notFound('مقدم الخدمة غير موجود.');
        $q = RahaCase::query()->where('provider_id', $id)->orderByDesc('updated_at');
        if ($this->permissions->scopeOf($user, 'cases.view') !== 'ALL') {
            $q->where(fn ($w) => $w->where('assignee', $user->name)
                ->orWhereIn('id', DB::table('case_tasks')->where('assigned_user_id', $user->id)->select('case_id')));
        }

        return $q->get()->map(fn (RahaCase $c) => array_filter([
            'id' => $c->id, 'beneficiaryId' => $c->beneficiary_id, 'beneficiary' => $c->beneficiary, 'service' => $c->service, 'serviceId' => $c->service_id,
            'status' => $c->status, 'stage' => $c->stage, 'date' => $c->date, 'updated' => $c->updated, 'createdAt' => iso($c->created_at), 'updatedAt' => iso($c->updated_at),
            'assignedAt' => iso($c->assigned_at), 'appointment' => $c->appointment, 'invoice' => $c->invoice,
        ], fn ($v) => $v !== null))->values()->all();
    }

    private function update(string $id, User $user, string $action, callable $fn): Provider
    {
        return DB::transaction(function () use ($id, $user, $action, $fn) {
            $p = Provider::query()->lockForUpdate()->find($id) ?? throw DomainRuleException::notFound('مقدم الخدمة غير موجود.');
            $fn($p);
            $p->touch();
            Activity::emit($action, 'مقدم خدمة', $id, $user);

            return $p->refresh()->load(['services', 'internalNotes']);
        });
    }

    private function requireManage(User $user): void
    {
        if (! $user->isStaff() || ! $this->permissions->has($user, 'providers.manage')) {
            throw DomainRuleException::forbidden();
        }
    }

    private function assertServicesExist(array $ids): void
    {
        if (count($ids) !== Service::query()->whereIn('id', $ids)->count()) {
            throw new DomainRuleException('الخدمة غير موجودة.');
        }
    }
}
