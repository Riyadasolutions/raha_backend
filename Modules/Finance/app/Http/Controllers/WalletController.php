<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\Services\FinanceQueries;
use Modules\Finance\Services\WalletBalances;
use Modules\Finance\Services\WalletService;

/** Support wallets and their ledger (account:STAFF). Reads need finance.wallets.view / finance.ledger.view, writes finance.wallets.manage. */
class WalletController extends Controller
{
    public function __construct(private WalletService $wallets, private FinanceQueries $queries, private WalletBalances $balances) {}

    private function input(Request $request): array
    {
        return $request->validate([
            'nameAr' => ['nullable', 'string', 'max:255'], 'nameEn' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:255'], 'eligibleServiceIds' => ['nullable', 'array'], 'eligibleServiceIds.*' => ['string', 'max:32'],
            'openingHalalas' => ['nullable'],
        ], FinanceController::MESSAGES);
    }

    private function one(string $id, Request $request, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->queries->wallet($id, $request->user())], $status);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queries->wallets($request->user())]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->one($id, $request);
    }

    public function store(Request $request): JsonResponse
    {
        $w = $this->wallets->create($this->input($request), $request->user());

        return response()->json(['data' => $this->balances->present($w)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $w = $this->wallets->update($id, $this->input($request), $request->user());

        return response()->json(['data' => $this->balances->present($w)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->wallets->remove($id, $request->user());

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    public function funds(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['amountHalalas' => ['required'], 'note' => ['nullable', 'string', 'max:2000']], FinanceController::MESSAGES);
        $w = $this->wallets->addFunds($id, $d['amountHalalas'], $d['note'] ?? null, $request->user());

        return response()->json(['data' => $this->balances->present($w->refresh())]);
    }

    public function adjust(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['amountHalalas' => ['required'], 'note' => ['nullable', 'string', 'max:2000']], FinanceController::MESSAGES);
        $w = $this->wallets->adjust($id, $d['amountHalalas'], $d['note'] ?? null, $request->user());

        return response()->json(['data' => $this->balances->present($w->refresh())]);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['active' => ['required', 'boolean']], FinanceController::MESSAGES);
        $w = $this->wallets->setStatus($id, (bool) $d['active'], $request->user());

        return response()->json(['data' => $this->balances->present($w)]);
    }

    public function ledger(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->queries->ledger($id, $request->user())]);
    }
}
