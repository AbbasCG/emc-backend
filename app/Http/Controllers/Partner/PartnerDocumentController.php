<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerDocument;
use App\Models\PartnerUser;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerDocumentController extends Controller
{
    private function getPartner(Request $request)
    {
        return PartnerUser::where('user_id', $request->user()->id)->where('status', 'active')->firstOrFail()->partner;
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $docs = $this->getPartner($request)
            ->documents()
            ->where('visibility', 'partner_visible')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $docs->map(fn ($d) => [
                'id'           => $d->id,
                'title'        => $d->title,
                'file_name'    => $d->file_name,
                'file_type'    => $d->file_type,
                'file_size'    => $d->file_size,
                'download_url' => route('partner.documents.download', $d->id),
            ]),
        ]);
    }

    public function store(Request $request, FileUploadService $uploads): \Illuminate\Http\JsonResponse
    {
        $partner = $this->getPartner($request);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'file'  => 'required|file|max:20480', // 20 MB — module also enforces an allow-list
        ]);

        $stored = $uploads->store($request->file('file'), 'partner_documents', (string) $partner->id);

        PartnerDocument::create([
            'partner_id'  => $partner->id,
            'uploaded_by' => $request->user()->id,
            'title'       => $data['title'],
            'file_path'   => $stored['path'],
            'file_name'   => $stored['original_name'],
            'file_type'   => $stored['mime'],
            'file_size'   => $stored['size'],
            'visibility'  => 'partner_visible',
        ]);

        return response()->json(['success' => true, 'message' => 'Document uploaded.'], 201);
    }

    /**
     * GET /api/partner/documents/{document}/download
     *
     * Streams the file through an authorized endpoint scoped to the partner
     * that owns the document — never expose the raw private storage path.
     */
    public function download(Request $request, PartnerDocument $document): StreamedResponse
    {
        $partner = $this->getPartner($request);

        abort_unless($document->partner_id === $partner->id, 403, 'You are not authorized to access this document.');

        abort_if(! $document->file_path, 404, 'This document has no file.');

        [$disk, $path] = $this->resolveDisk($document->file_path);

        abort_if($disk === null, 404, 'File not found.');

        return Storage::disk($disk)->download($path, $document->file_name);
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
