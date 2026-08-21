<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ConsultantApplication;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * تقديم طلب انضمام كمستشار — نقطة عامة بلا حساب، على نمط طلبات التطوع.
 * الطلبات المقبولة تُدار لاحقًا داخل «إدارة المستشارين».
 */
class ConsultantApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name'        => 'required|string|max:255',
            'email'            => 'required|email|max:255',
            'phone'            => 'nullable|string|max:30',
            'country'          => 'nullable|string|max:100',
            'city'             => 'nullable|string|max:100',
            'specialty'        => 'required|string|max:150',
            'years_experience' => 'nullable|integer|min:0|max:60',
            'linkedin_url'     => 'nullable|url|max:255',
            'cv_file'          => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'motivation'       => 'required|string|min:20',
            'availability'     => 'nullable|string|max:150',
            'agree_terms'      => 'accepted',
        ]);

        $cvPath = null;
        if ($request->hasFile('cv_file')) {
            $ext    = $request->file('cv_file')->getClientOriginalExtension();
            $safe   = 'consultant_cv_' . Str::uuid() . '.' . $ext;
            $cvPath = $request->file('cv_file')->storeAs('consultant-cvs', $safe, 'private');
        }

        $application = ConsultantApplication::create([
            'full_name'        => $data['full_name'],
            'email'            => $data['email'],
            'phone'            => $data['phone'] ?? null,
            'country'          => $data['country'] ?? null,
            'city'             => $data['city'] ?? null,
            'specialty'        => $data['specialty'],
            'years_experience' => $data['years_experience'] ?? null,
            'linkedin_url'     => $data['linkedin_url'] ?? null,
            'cv_file_path'     => $cvPath,
            'motivation'       => $data['motivation'],
            'availability'     => $data['availability'] ?? null,
            'status'           => 'new',
        ]);

        // إشعار داخلي للإدارة إن كانت قناة الإشعارات مفعّلة — فشلها لا يفشل الطلب.
        try {
            if (method_exists(NotificationService::class, 'genericAdminAlert')) {
                NotificationService::genericAdminAlert(
                    'طلب مستشار جديد',
                    "تقدم {$application->full_name} بطلب انضمام كمستشار في تخصص {$application->specialty}.",
                );
            }
        } catch (\Throwable) {
            // best-effort only
        }

        return response()->json([
            'success' => true,
            'message' => 'تم استلام طلبك كمستشار. سنتواصل معك بعد المراجعة.',
            'data'    => ['id' => $application->id, 'status' => $application->status],
        ], 201);
    }
}
