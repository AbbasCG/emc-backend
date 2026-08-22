<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AiAssessmentResult;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * «اختبر مستواك في AI» — استقبال النتيجة وإصدار مكافأة الاكتشاف:
 * كوبون حقيقي 7% صالح 72 ساعة، استخدام واحد. الحفظ لا يشترط بيانات تواصل
 * (النتيجة لا تُحجب)؛ ونداء لاحق بنفس reward_code يُكمل بيانات التواصل.
 */
class AiAssessmentController extends Controller
{
    public const REWARD_PERCENT = 7;
    public const REWARD_HOURS   = 72;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id'  => 'nullable|string|max:64',
            'level_id'    => 'required|in:explorer,power_user,automation_builder,solution_builder,systems_engineer',
            'level_title' => 'required|string|max:120',
            'overall'     => 'required|integer|min:0|max:100',
            'scores'      => 'required|array',
            'scores.*'    => 'integer|min:0|max:100',
            'goal'        => 'nullable|string|max:120',
            'field'       => 'nullable|string|max:120',
            'ref'         => 'nullable|string|max:64',
            // إكمال بيانات التواصل لاحقًا بنفس الكود
            'reward_code' => 'nullable|string|max:40',
            'name'        => 'nullable|string|max:120',
            'email'       => 'nullable|email|max:190',
            'whatsapp'    => 'nullable|string|max:40',
        ]);

        // نداء إكمال بيانات: صف موجود بنفس كود المكافأة
        if (! empty($data['reward_code'])) {
            $existing = AiAssessmentResult::where('reward_code', $data['reward_code'])->first();
            if ($existing) {
                $existing->update([
                    'name'     => $data['name'] ?? $existing->name,
                    'email'    => $data['email'] ?? $existing->email,
                    'whatsapp' => $data['whatsapp'] ?? $existing->whatsapp,
                ]);

                return response()->json(['success' => true, 'data' => ['updated' => true]]);
            }
        }

        $rewardCode = 'AI7-' . strtoupper(Str::random(6));
        $expiresAt  = now()->addHours(self::REWARD_HOURS);

        // كوبون حقيقي يعمل في الدفع — 7%، استخدام واحد، 72 ساعة.
        Coupon::create([
            'code'           => $rewardCode,
            'name'           => 'مكافأة اكتشاف مستواك — ' . self::REWARD_PERCENT . '%',
            'description'    => 'مكافأة إكمال تقييم EMC لمستوى الذكاء الاصطناعي، على البرامج المقترحة في النتيجة.',
            'discount_type'  => 'percentage',
            'discount_value' => self::REWARD_PERCENT,
            'currency'       => 'EUR',
            'max_uses'       => 1,
            'usage_limit_per_user' => 1,
            'starts_at'      => now(),
            'expires_at'     => $expiresAt,
            'status'         => 'active',
        ]);

        $result = AiAssessmentResult::create([
            'visitor_id'        => $data['visitor_id'] ?? null,
            'level_id'          => $data['level_id'],
            'level_title'       => $data['level_title'],
            'overall'           => $data['overall'],
            'scores'            => $data['scores'],
            'goal'              => $data['goal'] ?? null,
            'field'             => $data['field'] ?? null,
            'ref'               => $data['ref'] ?? null,
            'reward_code'       => $rewardCode,
            'reward_expires_at' => $expiresAt,
            'created_at'        => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                => $result->id,
                'reward_code'       => $rewardCode,
                'reward_percent'    => self::REWARD_PERCENT,
                'reward_expires_at' => $expiresAt->toIso8601String(),
            ],
        ], 201);
    }
}
