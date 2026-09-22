<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExportTaskStatus;
use App\Models\ExportTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $user = $request->user();
        $isOwnerOrManager = in_array($user?->role?->name, ['Owner', 'Manager'], true);

        abort_unless($isOwnerOrManager || $exportTask->requested_by === $user?->id, 403);
    }
}
