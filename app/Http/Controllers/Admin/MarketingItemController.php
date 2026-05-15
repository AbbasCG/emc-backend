<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketingItemResource;
use App\Models\MarketingItem;
use Illuminate\Http\Request;

class MarketingItemController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $items = MarketingItem::with('responsibleUser')
            ->when($request->platform, fn ($q) => $q->where('platform', $request->platform))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->responsible_user_id, fn ($q) => $q->where('responsible_user_id', $request->responsible_user_id))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => MarketingItemResource::collection($items)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'               => 'required|string|max:255',
            'description'         => 'nullable|string',
            'platform'            => 'in:instagram,linkedin,facebook,tiktok,website,email,whatsapp,other',
            'status'              => 'in:idea,writing,design,review,approved,scheduled,published,archived',
            'responsible_user_id' => 'nullable|exists:users,id',
            'related_type'        => 'nullable|string',
            'related_id'          => 'nullable|integer',
            'publish_date'        => 'nullable|date',
            'content_text'        => 'nullable|string',
            'assets'              => 'nullable|array',
        ]);

        $item = MarketingItem::create($data);

        return response()->json([
            'success' => true,
            'data'    => new MarketingItemResource($item->load('responsibleUser')),
        ], 201);
    }

    public function show(MarketingItem $marketingItem): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new MarketingItemResource($marketingItem->load('responsibleUser')),
        ]);
    }

    public function update(Request $request, MarketingItem $marketingItem): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'               => 'string|max:255',
            'description'         => 'nullable|string',
            'platform'            => 'in:instagram,linkedin,facebook,tiktok,website,email,whatsapp,other',
            'status'              => 'in:idea,writing,design,review,approved,scheduled,published,archived',
            'responsible_user_id' => 'nullable|exists:users,id',
            'related_type'        => 'nullable|string',
            'related_id'          => 'nullable|integer',
            'publish_date'        => 'nullable|date',
            'content_text'        => 'nullable|string',
            'assets'              => 'nullable|array',
        ]);

        $marketingItem->update($data);

        return response()->json([
            'success' => true,
            'data'    => new MarketingItemResource($marketingItem->load('responsibleUser')),
        ]);
    }

    public function destroy(MarketingItem $marketingItem): \Illuminate\Http\JsonResponse
    {
        $marketingItem->delete();

        return response()->json(['success' => true, 'message' => 'Marketing item deleted.']);
    }
}
