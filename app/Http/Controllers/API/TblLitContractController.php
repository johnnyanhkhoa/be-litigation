<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ExternalApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class TblLitContractController extends Controller
{
    protected $externalApiService;

    public function __construct(ExternalApiService $externalApiService)
    {
        $this->externalApiService = $externalApiService;
    }

    /**
     * GET /api/lit-contracts/{contractId}/litigation-journals
     * Get litigation journals for a contract from Maximus API
     */
    public function getLitigationJournals(Request $request, int $contractId): JsonResponse
    {
        try {
            // Validate query parameters
            $validator = Validator::make($request->all(), [
                'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $from = $request->input('from');
            $to = $request->input('to');

            Log::info('Fetching litigation journals from Maximus API', [
                'contract_id' => $contractId,
                'from' => $from,
                'to' => $to
            ]);

            // Call Maximus API
            $url = "contracts/{$contractId}/litigation-journals/from/{$from}/to/{$to}";
            $journalData = $this->externalApiService->get($url);

            Log::info('Litigation journals retrieved successfully', [
                'contract_id' => $contractId,
                'total_journals' => count($journalData['data'] ?? [])
            ]);

            return response()->json($journalData, 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch litigation journals', [
                'contract_id' => $contractId,
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 0,
                'message' => 'Failed to retrieve litigation journals',
                'data' => null,
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
