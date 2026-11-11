<?php

namespace Modules\Quotations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Quotations\Services\QuotationDocumentService;

/** Read-only quotation document model (account:STAFF,PROVIDER); the PDF is rendered client-side from it. */
class QuotationDocumentController extends Controller
{
    public function __construct(private QuotationDocumentService $documents) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['lang' => ['nullable', 'in:ar,en']], ['in' => 'قيمة غير صالحة.']);

        return response()->json(['data' => $this->documents->prepare($id, $request->user(), $d['lang'] ?? 'ar')]);
    }
}
