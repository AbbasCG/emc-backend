<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentFolder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentFolderController extends Controller
{
    public function index(): \Illuminate\Http\JsonResponse
    {
        $folders = DocumentFolder::with(['department', 'children'])
            ->whereNull('parent_id')
            ->get();

        return response()->json(['success' => true, 'data' => $folders]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'parent_id'     => 'nullable|exists:document_folders,id',
            'department_id' => 'nullable|exists:departments,id',
            'name'          => 'required|string|max:255',
            'slug'          => 'nullable|string|unique:document_folders,slug',
            'visibility'    => 'in:public,internal,department,private',
        ]);

        $data['slug']       = $data['slug'] ?? Str::slug($data['name']) . '-' . Str::random(4);
        $data['created_by'] = $request->user()->id;

        $folder = DocumentFolder::create($data);

        return response()->json(['success' => true, 'data' => $folder], 201);
    }

    public function update(Request $request, DocumentFolder $documentFolder): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'       => 'string|max:255',
            'visibility' => 'in:public,internal,department,private',
        ]);

        $documentFolder->update($data);

        return response()->json(['success' => true, 'data' => $documentFolder]);
    }

    public function destroy(DocumentFolder $documentFolder): \Illuminate\Http\JsonResponse
    {
        $documentFolder->delete();

        return response()->json(['success' => true, 'message' => 'Folder deleted.']);
    }
}
