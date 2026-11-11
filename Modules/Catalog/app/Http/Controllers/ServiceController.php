<?php

namespace Modules\Catalog\Http\Controllers;

use App\Support\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Catalog\Models\Service;
use Modules\Catalog\Services\ServiceCatalog;
use Modules\Catalog\Services\ServiceDelivery;

/** Services management (front serviceRepo) + the beneficiary-facing requestable list. */
class ServiceController extends Controller
{
    public function __construct(private readonly ServiceCatalog $catalog, private readonly PermissionResolver $permissions) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissions->require($request->user(), 'services.view');

        return response()->json(['data' => $this->catalog->all()->map->toFront()->values()]);
    }

    public function publicIndex(): JsonResponse
    {
        return response()->json(['data' => Service::query()->where('status', 'active')->where(fn ($q) => $q->whereNull('public_visible')->orWhere('public_visible', true))
            ->orderBy('id')->get()->map->toPublic()->values()]);
    }

    /** Any authenticated account: active + portal-visible services in configured order (new-request wizard). */
    public function requestable(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->requestable()->map(fn (Service $s) => ServiceCatalog::toRequestable($s))->values()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->permissions->require($request->user(), 'services.view');

        return response()->json(['data' => $this->catalog->get($id)->toFront()]);
    }

    public function store(Request $request): JsonResponse
    {
        $service = $this->catalog->save($this->validated($request, true), $request->user());

        return response()->json(['data' => $service->toFront()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->catalog->get($id);

        return response()->json(['data' => $this->catalog->save($this->validated($request, false), $request->user(), $id)->toFront()]);
    }

    public function toggle(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalog->toggle($id, $request->user())->toFront()]);
    }

    /** Field shape validation; business rules (delivery, duplicates, extra docs) live in ServiceCatalog::save. */
    private function validated(Request $request, bool $creating): array
    {
        $this->permissions->require($request->user(), 'services.manage');
        // Delivery is validated on the raw input (types matter: "3" is not a positive integer).
        if ($bad = ServiceDelivery::validate($request->only(['deliveryType', 'defaultVisitCount']))) {
            throw new \App\Exceptions\DomainRuleException($bad);
        }
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'min:3', 'max:120'], 'nameEn' => ['nullable', 'string', 'max:120'],
            'category' => [$req, 'string', 'max:60'], 'description' => [$req, 'string', 'max:2000'],
            'status' => ['sometimes', 'in:active,inactive'],
            'requiresMedicalReport' => ['sometimes', 'boolean'], 'requiresDocuments' => ['sometimes', 'boolean'],
            'allowInternal' => ['sometimes', 'boolean'], 'allowExternal' => ['sometimes', 'boolean'],
            'publicVisible' => ['nullable', 'boolean'], 'portalVisible' => ['nullable', 'boolean'], 'sortOrder' => ['nullable', 'integer'],
            'subservices' => ['nullable', 'array'], 'subservices.*' => ['string', 'max:120'],
            'requirements' => ['nullable', 'array'], 'requirements.medicalReport' => ['sometimes', 'boolean'], 'requirements.prescription' => ['sometimes', 'boolean'],
            'requirements.needDescription' => ['sometimes', 'boolean'], 'requirements.reportMaxAgeDays' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'requirements.extraDocs' => ['sometimes', 'array'], 'requirements.extraDocs.*.label' => ['nullable', 'string', 'max:120'],
            'requirements.extraDocs.*.required' => ['sometimes', 'boolean'], 'requirements.extraDocs.*.active' => ['sometimes', 'boolean'],
            'requirements.extraDocs.*.explanation' => ['nullable', 'string', 'max:500'], 'requirements.note' => ['nullable', 'string', 'max:500'],
            'deliveryType' => ['nullable'], 'defaultVisitCount' => ['nullable'],
        ], [
            'name.required' => 'اسم الخدمة مطلوب (٣ أحرف على الأقل).', 'name.min' => 'اسم الخدمة مطلوب (٣ أحرف على الأقل).',
            'description.required' => 'الوصف مطلوب.', 'category.required' => 'التصنيف مطلوب.', 'status.in' => 'حالة الخدمة غير صحيحة.',
        ]);
        if (isset($data['description']) && trim($data['description']) === '') {
            throw new \App\Exceptions\DomainRuleException('الوصف مطلوب.');
        }
        // Keep the raw (unvalidated-cast) delivery values exactly as sent.
        foreach (['deliveryType', 'defaultVisitCount'] as $k) {
            if ($request->has($k)) {
                $data[$k] = $request->input($k);
            }
        }

        return $data;
    }
}
