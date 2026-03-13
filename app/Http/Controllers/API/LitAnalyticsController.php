<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\LitAnalyticsService;
use Illuminate\Http\Request;

class LitAnalyticsController extends Controller
{
    protected LitAnalyticsService $analyticsService;

    public function __construct(LitAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get Litigation analytics summary
     *
     * GET /api/lit-phone-collections/analytics-summary?from=2026-01-01&to=2026-01-31
     */
    public function getAnalyticsSummary(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from'
        ]);

        $data = $this->analyticsService->getAnalyticsSummary(
            $request->from,
            $request->to
        );

        return response()->json([
            'success' => true,
            'message' => 'Litigation analytics data retrieved successfully',
            'data' => $data,
            'dateRange' => [
                'from' => $request->from,
                'to' => $request->to
            ]
        ]);
    }
}
