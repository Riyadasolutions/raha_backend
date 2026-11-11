<?php

namespace Modules\Cases\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cases\Services\CaseAccess;
use Modules\Cases\Services\CaseProjector;
use Modules\Cases\Services\SubmissionService;

/** POST /beneficiaries/{id}/requests — staff creates a request on behalf of a beneficiary (no survey, no Fast Track). */
class BeneficiaryRequestController extends Controller
{
    public function store(Request $request, string $id, SubmissionService $submission, CaseProjector $projector, CaseAccess $access): JsonResponse
    {
        $rules = PortalController::submitRules();
        unset($rules['requestFor'], $rules['relationship'], $rules['patient'], $rules['patient.fullName'], $rules['patient.*']);
        $data = $request->validate($rules, PortalController::submitMessages());
        unset($data['patient'], $data['requestFor'], $data['relationship']);
        $user = $request->user();
        $c = $submission->submit($user, $data, $id);
        $c->load('tasks');

        return response()->json(['data' => $access->canAccessCase($c, $user) ? $projector->forStaff($c, $user) : ['id' => $c->id, 'status' => $c->status]], 201);
    }
}
