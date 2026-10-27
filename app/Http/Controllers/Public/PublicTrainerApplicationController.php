<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\TrainerApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicTrainerApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // أولاً: البيانات الشخصية
            'full_name' => 'required|string|max:255',
            'phone_or_whatsapp' => 'required|string|max:50',
            'email' => 'required|email|max:255',
            'linkedin_url' => 'nullable|string|url|max:500',

            // ثانياً: التخصص والخبرة
            'primary_specialty' => 'required|string|max:255',
            'years_of_experience' => 'required|string|max:100',
            'has_training_experience' => 'required|boolean',

            // ثالثاً: مجالات التدريب
            'ai_training_fields' => 'required|array|min:1',
            'ai_training_fields.*' => 'string|max:255',
            'other_ai_field' => 'nullable|string|max:255',
            'training_program_types' => 'required|array|min:1',
            'training_program_types.*' => 'string|max:255',
            'target_audiences' => 'required|array|min:1',
            'target_audiences.*' => 'string|max:255',

            // رابعاً: آلية التعاون والتسعير
            'delivery_mode' => 'required|string|max:100',
            'hourly_rate' => 'nullable|numeric|min:0',
            'currency' => 'required|string|in:USD,SAR,YER,EUR,OTHER',
            'currency_other' => 'nullable|string|max:50',
            'additional_notes' => 'nullable|string|max:3000',
        ]);

        try {
            $application = TrainerApplication::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'تم استلام طلب انضمام المدرب بنجاح.',
                'uuid' => $application->uuid,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Error storing trainer application: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'تعذر حفظ طلب المدرب في الوقت الحالي، يرجى المحاولة لاحقاً.',
            ], 500);
        }
    }
}
