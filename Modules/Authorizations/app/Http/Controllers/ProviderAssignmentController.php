<?php

namespace Modules\Authorizations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Authorizations\Services\AuthorizationService;

/** Provider portal: own assignments + acknowledgement (account:PROVIDER). */
class ProviderAssignmentController extends Controller
{
    public function __construct(private AuthorizationService $authorizations) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->authorizations->forProvider($request->user())]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $this->authorizations->acknowledge($id, $request->user());
        $row = collect($this->authorizations->forProvider($request->user()))->firstWhere('id', $id);

        return response()->json(['data' => $row]);
    }
}
