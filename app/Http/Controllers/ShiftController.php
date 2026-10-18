<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function __construct(protected ShiftService $shiftService) {}

    /**
     * Menampilkan dashboard manajemen shift.
     */
    public function index(): View
    {
        Gate::authorize('viewAny', Shift::class);

        return view('shifts.index');
    }

    /**
     * Membuka shift baru untuk pengguna saat ini melalui ShiftService.
     */
    public function open(Request $request): JsonResponse
    {
        Gate::authorize('open', Shift::class);

        $validated = $request->validate([
            'opening_balance' => 'required|integer|min:0',
        ]);

        try {
            $shift = $this->shiftService->openShift((int) Auth::id(), (int) $validated['opening_balance']);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'require_pin_setup' => true,
            ], 422);
        }

        if (! $shift) {
            return response()->json([
                'success' => false,
                'message' => 'Anda masih memiliki shift yang sedang aktif atau shift gagal dibuka.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Shift berhasil dibuka.',
            'shift' => $shift,
        ]);
    }

    /**
     * Menutup shift aktif melalui ShiftService.
     */
    public function close(Request $request, Shift $shift): JsonResponse
    {
        Gate::authorize('close', $shift);

        $validated = $request->validate([
            'closing_balance' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $closed = $this->shiftService->closeShift(
            (int) Auth::id(),
            (int) $validated['closing_balance'],
            $validated['notes'] ?? null
        );

        if (! $closed) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menutup shift atau tidak ada shift aktif.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Shift berhasil ditutup.',
            'shift' => $closed,
        ]);
    }

    /**
     * Mencatat pergerakan kas laci (cash in / cash out) melalui ShiftService.
     */
    public function recordCashMovement(Request $request, Shift $shift): JsonResponse
    {
        Gate::authorize('recordCashMovement', $shift);

        $validated = $request->validate([
            'type' => 'required|string|in:cash_in,cash_out',
            'amount' => 'required|integer|min:1',
            'category' => 'required|string|max:100',
            'reason' => 'required|string|max:255',
            'receipt_reference' => 'nullable|string|max:100',
        ]);

        try {
            $movement = $this->shiftService->recordCashMovement(
                (int) Auth::id(),
                $shift->id,
                $validated['type'],
                (int) $validated['amount'],
                $validated['category'],
                $validated['reason'],
                $validated['receipt_reference'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Pergerakan kas berhasil dicatat.',
                'movement' => $movement,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Menampilkan ringkasan kas laci shift.
     */
    public function show(Shift $shift): JsonResponse
    {
        Gate::authorize('view', $shift);

        $summary = $this->shiftService->getCashSummary($shift);

        return response()->json([
            'success' => true,
            'shift' => $shift,
            'summary' => $summary,
        ]);
    }
}
