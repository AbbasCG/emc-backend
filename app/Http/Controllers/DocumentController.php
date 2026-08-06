<?php

namespace App\Http\Controllers;

use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        $docs = Document::with(['folder', 'uploadedBy'])
            ->when(
                $user && $user->role === 'admin',
                fn ($q) => $q,
                fn ($q) => $q->whereIn('visibility', ['public', 'internal'])
            )
            ->when($request->folder_id, fn ($q) => $q->where('folder_id', $request->folder_id))
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data'    => DocumentResource::collection($docs)->response()->getData(true),
        ]);
    }

    public function store(Request $request, FileUploadService $uploads): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'folder_id'     => 'nullable|exists:document_folders,id',
            'department_id' => 'nullable|exists:departments,id',
            'visibility'    => 'in:public,internal,department,private',
            'tags'          => 'nullable|array',
            'file'          => 'required|file|max:20480', // 20 MB — module also enforces an allow-list
        ]);

        $stored = $uploads->store($request->file('file'), 'documents');

        $doc = Document::create([
            'folder_id'     => $data['folder_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'uploaded_by'   => $request->user()->id,
            'title'         => $data['title'],
            'file_path'     => $stored['path'],
            'file_name'     => $stored['original_name'],
            'file_type'     => $stored['mime'],
            'file_size'     => $stored['size'],
            'visibility'    => $data['visibility'] ?? 'internal',
            'tags'          => $data['tags'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => new DocumentResource($doc->load('uploadedBy')),
        ], 201);
    }

    /**
     * GET /api/documents/{document}/download
     *
     * Streams the file through an authorized endpoint — never expose the raw
     * private storage path directly to clients.
     */
    public function download(Request $request, Document $document): StreamedResponse
    {
        $user = $request->user();

        abort_unless($this->canAccess($user, $document), 403, 'You are not authorized to access this document.');

        abort_if(! $document->file_path, 404, 'This document has no file.');

        [$disk, $path] = $this->resolveDisk($document->file_path);

        abort_if($disk === null, 404, 'File not found.');

        return Storage::disk($disk)->download($path, $document->file_name);
    }

    private function canAccess($user, Document $document): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->role === 'admin' || $user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        if ($document->uploaded_by === $user->id) {
            return true;
        }

        return in_array($document->visibility, ['public', 'internal'], true);
    }

    /**
     * Backward-compatible disk resolution: new uploads live on the private
     * disk, but documents uploaded before this fix may still be on `public`.
     */
    private function resolveDisk(string $path): array
    {
        if (Storage::disk('private')->exists($path)) {
            return ['private', $path];
        }

        if (Storage::disk('public')->exists($path)) {
            return ['public', $path];
        }

        return [null, $path];
    }
}
