<?php

namespace Modules\Catalog\Http\Controllers;

use App\Exceptions\DomainRuleException;
use App\Support\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Catalog\Models\Provider;
use Modules\Catalog\Services\ProviderCatalog;

/** Service providers (front providerRepo). Staff see all providers; provider accounts only their own record without internal notes. */
class ProviderController extends Controller
{
    public function __construct(private readonly ProviderCatalog $providers, private readonly PermissionResolver $permissions) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->providers->visibleTo($user)->map(fn (Provider $p) => $this->providers->serialize($p, $user))->values()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->providers->serialize($this->providers->get($id, $request->user()), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $p = $this->providers->save($this->validated($request, true), $request->user());

        return response()->json(['data' => $this->providers->serialize($p, $request->user())], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $p = $this->providers->save($this->validated($request, false), $request->user(), $id);

        return response()->json(['data' => $this->providers->serialize($p, $request->user())]);
    }

    public function addService(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['serviceId' => ['required', 'string']], ['serviceId.required' => 'اختر الخدمة.']);

        return response()->json(['data' => $this->providers->serialize($this->providers->addService($id, $data['serviceId'], $request->user()), $request->user())]);
    }

    public function removeService(Request $request, string $id, string $serviceId): JsonResponse
    {
        return response()->json(['data' => $this->providers->serialize($this->providers->removeService($id, $serviceId, $request->user()), $request->user())]);
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']], ['text.required' => 'اكتب الملاحظة أولًا']);

        return response()->json(['data' => $this->providers->serialize($this->providers->addNote($id, trim($data['text']), $request->user()), $request->user())], 201);
    }

    public function cases(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->providers->cases($id, $request->user())]);
    }

    /** Port of the provider form validation (the mock only checked it in the browser). */
    private function validated(Request $request, bool $creating): array
    {
        $user = $request->user();
        if (! $user->isStaff() || ! $this->permissions->has($user, 'providers.manage')) {
            throw DomainRuleException::forbidden();
        }
        $req = $creating ? 'required' : 'sometimes';
        $phone = 'regex:/^0\d{8,9}$/';

        $data = $request->validate([
            'name' => [$req, 'string', 'min:3', 'max:160'], 'type' => [$req, 'string', 'max:60'], 'license' => [$req, 'string', 'max:60'],
            'registry' => ['nullable', 'string', 'max:60'], 'phone' => [$req, 'string', $phone], 'email' => ['nullable', 'regex:/^\S+@\S+\.\S+$/'],
            'city' => [$req, 'string', 'max:60'], 'district' => ['nullable', 'string', 'max:60'], 'address' => ['nullable', 'string', 'max:200'],
            'contact' => [$req, 'array'], 'contact.name' => [$creating ? 'required' : 'required_with:contact', 'string', 'max:120'],
            'contact.mobile' => ['nullable', 'string', $phone], 'contact.email' => ['nullable', 'regex:/^\S+@\S+\.\S+$/'],
            'services' => [$req, 'array', 'min:1'], 'services.*.serviceId' => ['required', 'string'], 'services.*.addedAt' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:active,inactive'], 'notes' => ['nullable', 'string', 'max:2000'], 'logo' => ['nullable', 'string'],
        ], [
            'name.required' => 'اسم مقدم الخدمة مطلوب.', 'name.min' => 'اسم مقدم الخدمة مطلوب.', 'license.required' => 'رقم الترخيص مطلوب.',
            'phone.required' => 'رقم تواصل صحيح يبدأ بـ 0.', 'phone.regex' => 'رقم تواصل صحيح يبدأ بـ 0.', 'email.regex' => 'بريد إلكتروني غير صحيح.',
            'city.required' => 'المدينة مطلوبة.', 'contact.required' => 'اسم مسؤول التواصل مطلوب.', 'contact.name.required' => 'اسم مسؤول التواصل مطلوب.',
            'contact.name.required_with' => 'اسم مسؤول التواصل مطلوب.', 'contact.mobile.regex' => 'رقم جوال غير صحيح.', 'contact.email.regex' => 'بريد إلكتروني غير صحيح.',
            'services.required' => 'اختر خدمة واحدة على الأقل.', 'services.min' => 'اختر خدمة واحدة على الأقل.', 'type.required' => 'نوع مقدم الخدمة مطلوب.',
            'status.in' => 'حالة مقدم الخدمة غير صحيحة.',
        ]);
        if ($request->has('logo')) {
            $data['logo'] = $request->input('logo');
        }

        return $data;
    }
}
