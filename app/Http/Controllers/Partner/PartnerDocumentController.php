<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerDocument;
use App\Models\PartnerUser;
use Illuminate\Http\Request;

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
                'id'        => $d->id,
                'title'     => $d->title,
                'file_name' => $d->file_name,
                'file_type' => $d->file_type,
                'file_size' => $d->file_size,
            ]),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $partner = $this->getPartner($request);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'file'  => 'required|file|max:20480', // 20 MB
        ]);

        $file = $request->file('file');
        $path = $file->store("partners/{$partner->id}/documents", 'public');

        PartnerDocument::create([
            'partner_id'  => $partner->id,
            'uploaded_by' => $request->user()->id,
            'title'       => $data['title'],
            'file_path'   => $path,
            'file_name'   => $file->getClientOriginalName(),
            'file_type'   => $file->getClientMimeType(),
            'file_size'   => $file->getSize(),
            'visibility'  => 'partner_visible',
        ]);

        return response()->json(['success' => true, 'message' => 'Document uploaded.'], 201);
    }
}
