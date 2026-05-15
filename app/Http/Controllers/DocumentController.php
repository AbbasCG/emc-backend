<?php

namespace App\Http\Controllers;

use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Http\Request;

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

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'folder_id'     => 'nullable|exists:document_folders,id',
            'department_id' => 'nullable|exists:departments,id',
            'visibility'    => 'in:public,internal,department,private',
            'tags'          => 'nullable|array',
            'file'          => 'required|file|max:51200', // 50 MB
        ]);

        $file = $request->file('file');
        $path = $file->store('documents', 'public');

        $doc = Document::create([
            'folder_id'     => $data['folder_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'uploaded_by'   => $request->user()->id,
            'title'         => $data['title'],
            'file_path'     => $path,
            'file_name'     => $file->getClientOriginalName(),
            'file_type'     => $file->getClientMimeType(),
            'file_size'     => $file->getSize(),
            'visibility'    => $data['visibility'] ?? 'internal',
            'tags'          => $data['tags'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => new DocumentResource($doc->load('uploadedBy')),
        ], 201);
    }
}
