<?php

namespace Modules\Cases\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cases\Services\DocumentService;

/** POST /uploads — any authenticated account stores a file before attaching it to a request or document. */
class UploadController extends Controller
{
    public function __construct(private DocumentService $documents) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']], ['file.required' => 'يرجى اختيار ملف.', 'file.file' => 'يرجى اختيار ملف.', 'file.uploaded' => 'تعذر رفع الملف.']);

        return response()->json(['data' => $this->documents->storeUpload($request->file('file'), $request->user())->toFront()], 201);
    }
}
