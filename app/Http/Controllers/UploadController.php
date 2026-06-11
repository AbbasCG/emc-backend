<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadRequest;
use App\Models\UploadRecord;
use App\Services\CompressedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadController extends Controller
{
    public function __construct(private readonly CompressedUploadService $uploader) {}

    /**
     * POST /api/uploads/{context}
     *
     * Accepts an image or PDF (depending on context), compresses it, stores only
     * the optimised copy, and returns upload metadata. Large files are queued and
     * the response returns 202 with a status_url to poll.
     */
    public function store(UploadRequest $request, string $context): JsonResponse
    {
        $result = $this->uploader->upload(
            $request->file('file'),
            $context,
            $request->user()->id
        );

        $httpStatus = ($result['status'] ?? 'complete') === 'processing' ? 202 : 200;

        return response()->json(['success' => true, 'data' => $result], $httpStatus);
    }

    /**
     * GET /api/uploads/status/{record}
     *
     * Returns the current processing status and metadata for a queued upload.
     */
    public function status(Request $request, UploadRecord $record): JsonResponse
    {
        if ($request->user()->id !== $record->user_id && ! $request->user()->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->uploader->format($record)]);
    }

    /**
     * GET /api/uploads/download/{record}[?thumb=1]
     *
     * Streams a private (local-disk) file or its thumbnail to the authenticated owner or admin.
     */
    public function download(Request $request, UploadRecord $record): StreamedResponse|JsonResponse
    {
        if ($request->user()->id !== $record->user_id && ! $request->user()->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($record->status !== 'complete') {
            return response()->json(['success' => false, 'message' => 'File not ready.'], 404);
        }

        $isThumb = $request->boolean('thumb');
        $path    = $isThumb && $record->thumbnail_path ? $record->thumbnail_path : $record->path;

        if (! $path) {
            return response()->json(['success' => false, 'message' => 'File not found.'], 404);
        }

        $basename = pathinfo($record->original_filename, PATHINFO_FILENAME);
        $filename = $isThumb ? $basename . '_thumb.webp' : $record->original_filename;

        return Storage::disk($record->disk)->download($path, $filename);
    }
}
