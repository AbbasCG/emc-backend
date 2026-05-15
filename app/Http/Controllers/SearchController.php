<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, SearchService $search): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:200']);

        $results = $search->search($data['q'], $request->user()?->role);

        return response()->json([
            'success' => true,
            'query'   => $data['q'],
            'data'    => $results,
        ]);
    }
}
