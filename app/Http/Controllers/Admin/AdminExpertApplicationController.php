<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpertApplication;
use Illuminate\Http\Request;

class AdminExpertApplicationController extends Controller
{
    /**
     * Display a listing of the expert applications.
     */
    public function index(Request $request)
    {
        $applications = ExpertApplication::latest()->paginate(15);
        return response()->json($applications);
    }

    /**
     * Display the specified expert application.
     */
    public function show($id)
    {
        $application = ExpertApplication::findOrFail($id);
        return response()->json($application);
    }

    /**
     * Update the status of the specified application.
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:pending,reviewed,accepted,rejected',
        ]);

        $application = ExpertApplication::findOrFail($id);
        $application->status = $request->status;
        $application->save();

        return response()->json(['message' => 'تم تحديث حالة الطلب بنجاح', 'application' => $application]);
    }
}
