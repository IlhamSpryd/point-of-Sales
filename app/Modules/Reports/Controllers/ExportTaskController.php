<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Enums\ExportTaskStatus;
use App\Http\Controllers\Controller;
use App\Models\ExportTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportTaskController extends Controller
{
    public function status(Request $request, ExportTask $exportTask): JsonResponse
    {
        $this->authorizeAccess($request, $exportTask);

        return response()->json([
            'id' => $exportTask->id,
            'status' => $exportTask->status->value,
            'error_message' => $exportTask->error_message,
            'completed_at' => $exportTask->completed_at?->toIso8601String(),
            'download_url' => $exportTask->status === ExportTaskStatus::Completed
                ? route('exports.download', $exportTask)
                : null,
        ]);
    }

    public function download(Request $request, ExportTask $exportTask): StreamedResponse
    {
        $this->authorizeAccess($request, $exportTask);

        abort_unless($exportTask->status === ExportTaskStatus::Completed && $exportTask->file_path, 404);
        abort_unless(Storage::disk('local')->exists($exportTask->file_path), 404, 'File ekspor sudah tidak ada di server.');

        return Storage::disk('local')->download($exportTask->file_path);
    }

    private function authorizeAccess(Request $request, ExportTask $exportTask): void
    {
        Gate::authorize('download', $exportTask);

        $user = $request->user();

        // Validasi direktori penyimpanan terisolasi per tenant (mencegah path traversal dan kebocoran lintas tenant)
        if ($exportTask->file_path) {
            abort_if(str_contains($exportTask->file_path, '..'), 403);
            if (str_starts_with($exportTask->file_path, 'exports/tenant_')) {
                abort_unless(str_starts_with($exportTask->file_path, "exports/tenant_{$user->tenant_id}/"), 403);
            }
        }
    }
}
