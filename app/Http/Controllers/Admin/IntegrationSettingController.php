<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = IntegrationSetting::query()
            ->latest()
            ->get()
            ->map(fn (IntegrationSetting $s) => [
                'id'         => $s->id,
                'key'        => $s->key,
                'provider'   => $s->provider,
                'config'     => $s->safeConfig(), // secrets masked
                'status'     => $s->status,
                'updated_at' => $s->updated_at,
            ]);

        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function upsert(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'sometimes|nullable|string|max:100',
            'config'   => 'required|array',
            'status'   => 'sometimes|in:active,inactive',
        ]);

        $setting = IntegrationSetting::query()->firstOrNew(['key' => $key]);
        $setting->provider   = $data['provider'] ?? $setting->provider;
        $setting->config     = $data['config'];
        $setting->status     = $data['status'] ?? $setting->status ?? 'inactive';
        $setting->created_by = $setting->created_by ?? $request->user()->id;
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => "Integration [{$key}] saved.",
            'data'    => [
                'key'    => $setting->key,
                'status' => $setting->status,
                'config' => $setting->safeConfig(),
            ],
        ]);
    }
}
