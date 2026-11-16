<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the cancelled volunteer HR-profile pipeline (config volunteer_onboarding.hr_profile_pipeline_enabled).
 *
 * Applied only to the WRITE endpoints of that pipeline (volunteer create/update, HR review/approve/reject). When the switch is off they answer
 * 410 Gone. Read endpoints are never gated, so historical profiles stay visible. No data or code is removed.
 */
class EnsureVolunteerHrPipelineEnabled
{
    public const MESSAGE = 'تم إيقاف هذا المسار. التقديم للتطوع يتم الآن عبر طلب التطوع، وبعد قبوله تُنشأ لك عضوية وحساب.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('volunteer_onboarding.hr_profile_pipeline_enabled')) {
            return response()->json([
                'success' => false,
                'code'    => 'VOLUNTEER_HR_PIPELINE_DISABLED',
                'message' => self::MESSAGE,
            ], 410);
        }

        return $next($request);
    }
}
