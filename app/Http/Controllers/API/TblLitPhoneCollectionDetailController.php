<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TblLitCaseResult;
use App\Models\TblLitPhoneCollection;
use App\Models\TblLitPhoneCollectionDetail;
use App\Models\UserReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Services\UserReferenceService;

class TblLitPhoneCollectionDetailController extends Controller
{
    protected $userRefService;

    public function __construct(UserReferenceService $userRefService)
    {
        $this->userRefService = $userRefService;
    }

    /**
     * GET /api/lit-attempts/{litPhoneCollectionId}
     * Get call attempts for a specific Litigation phone collection
     */
    public function getCallAttempts(int $litPhoneCollectionId): JsonResponse
    {
        try {
            // Validate litPhoneCollectionId
            if ($litPhoneCollectionId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid litigation phone collection ID',
                    'error' => 'Litigation phone collection ID must be a positive integer'
                ], 400);
            }

            Log::info('Fetching call attempts for litigation phone collection', [
                'lit_phone_collection_id' => $litPhoneCollectionId
            ]);

            // Check if phone collection exists
            $phoneCollection = TblLitPhoneCollection::find($litPhoneCollectionId);
            if (!$phoneCollection) {
                return response()->json([
                    'success' => false,
                    'message' => 'Litigation phone collection not found',
                    'error' => 'The specified litigation phone collection does not exist'
                ], 404);
            }

            // Get ALL call attempts for this litPhoneCollectionId
            $attempts = TblLitPhoneCollectionDetail::with(['caseResult', 'creator'])
                ->where('litPhoneCollectionId', $litPhoneCollectionId)
                ->whereNull('deletedAt')
                ->orderBy('createdAt', 'desc')
                ->get();

            // Transform attempts
            $transformedAttempts = $attempts->map(function($attempt) {
                return [
                    'litPhoneCollectionDetailId' => $attempt->litPhoneCollectionDetailId,
                    'litPhoneCollectionId' => $attempt->litPhoneCollectionId,
                    'contactType' => $attempt->contactType,
                    'phoneId' => $attempt->phoneId,
                    'contactDetailId' => $attempt->contactDetailId,
                    'contactPhoneNumber' => $attempt->contactPhoneNumber,
                    'contactName' => $attempt->contactName,
                    'contactRelation' => $attempt->contactRelation,
                    'callStatus' => $attempt->callStatus,
                    'caseResultId' => $attempt->caseResultId,
                    'caseResultName' => $attempt->caseResult->caseResultName ?? null,
                    'remark' => $attempt->remark,
                    'promisedPaymentDate' => $attempt->promisedPaymentDate?->format('Y-m-d'),
                    'promisedPaymentAmount' => $attempt->promisedPaymentAmount,
                    'claimedPaymentDate' => $attempt->claimedPaymentDate?->format('Y-m-d'),
                    'claimedPaymentAmount' => $attempt->claimedPaymentAmount,
                    'dtCallStarted' => $attempt->dtCallStarted?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'dtCallEnded' => $attempt->dtCallEnded?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'isUncontactable' => $attempt->isUncontactable,
                    'createdAt' => $attempt->createdAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'createdBy' => $attempt->createdBy,
                    'createdByUserFullName' => $attempt->creator->userFullName ?? null,
                ];
            });

            // Calculate summary
            $summary = [
                'byContactType' => [],
                'byCallStatus' => [],
                'latestAttempt' => null,
                'oldestAttempt' => null,
            ];

            if ($attempts->count() > 0) {
                $summary['byContactType'] = $attempts->groupBy('contactType')->map->count()->toArray();
                $summary['byCallStatus'] = $attempts->groupBy('callStatus')->map->count()->toArray();

                $firstAttempt = $attempts->first();
                if ($firstAttempt && $firstAttempt->createdAt) {
                    $summary['latestAttempt'] = $firstAttempt->createdAt->utc()->format('Y-m-d\TH:i:s\Z');
                }

                $lastAttempt = $attempts->last();
                if ($lastAttempt && $lastAttempt->createdAt) {
                    $summary['oldestAttempt'] = $lastAttempt->createdAt->utc()->format('Y-m-d\TH:i:s\Z');
                }
            }

            Log::info('Litigation call attempts fetched successfully', [
                'lit_phone_collection_id' => $litPhoneCollectionId,
                'total_attempts' => $attempts->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Call attempts retrieved successfully',
                'data' => [
                    'litPhoneCollectionId' => $litPhoneCollectionId,
                    'phoneCollection' => [
                        'litPhoneCollectionId' => $phoneCollection->litPhoneCollectionId,
                        'litCaseId' => $phoneCollection->litCaseId,
                        'contractId' => $phoneCollection->contractId,
                        'customerFullName' => $phoneCollection->customerFullName,
                        'status' => $phoneCollection->status,
                        'totalAttempts' => $phoneCollection->totalAttempts,
                        'lastAttemptAt' => $phoneCollection->lastAttemptAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                        'cycleId' => $phoneCollection->cycleId,
                        'dueDate' => $phoneCollection->dueDate?->format('Y-m-d'),
                        'totalOvdAmount' => $phoneCollection->totalOvdAmount,
                    ],
                    'attempts' => $transformedAttempts,
                    'totalAttempts' => $attempts->count(),
                    'summary' => $summary
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch litigation call attempts', [
                'lit_phone_collection_id' => $litPhoneCollectionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve call attempts',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * GET /api/lit-phone-collection-details/contract/{contractId}/remarks
     * Get all remarks for a contract (Historical CC/WOCC/LCC + Current Litigation)
     */
    public function getRemarksByContract(int $contractId): JsonResponse
    {
        try {
            if ($contractId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid contract ID',
                ], 400);
            }

            Log::info('Fetching remarks for contract (CC historical + Litigation)', [
                'contract_id' => $contractId
            ]);

            // Step 1: Call CC BE API to get historical remarks (CC + WOCC + LCC)
            $ccRemarks = $this->fetchCCHistoricalRemarks($contractId);

            // Step 2: Get Litigation phone collections and remarks
            $litPhoneCollections = TblLitPhoneCollection::where('contractId', $contractId)
                ->whereNull('deletedAt')
                ->orderBy('litPhoneCollectionId', 'desc')
                ->get();

            $litRemarks = collect();

            if ($litPhoneCollections->isNotEmpty()) {
                $litIds = $litPhoneCollections->pluck('litPhoneCollectionId')->toArray();

                $litDetails = TblLitPhoneCollectionDetail::with(['caseResult', 'creator'])
                    ->whereIn('litPhoneCollectionId', $litIds)
                    ->whereNull('deletedAt')
                    ->orderBy('createdAt', 'desc')
                    ->get();

                // Transform Litigation remarks to match CC format
                $litRemarks = $litDetails->map(function($detail) {
                    return [
                        'litPhoneCollectionDetailId' => $detail->litPhoneCollectionDetailId,
                        'litPhoneCollectionId' => $detail->litPhoneCollectionId,
                        'phoneCollectionDetailId' => null, // For compatibility
                        'phoneCollectionId' => null,
                        'contractId' => $detail->phoneCollection->contractId ?? null,
                        'customerFullName' => $detail->phoneCollection->customerFullName ?? null,
                        'remark' => $detail->remark,
                        'standardRemarkContent' => null, // Lit doesn't have standard remarks
                        'standardRemarkId' => null,
                        'standardRemark' => null,
                        'reasonId' => null,
                        'reasonName' => null,
                        'reasonRemark' => null,
                        'contactType' => $detail->contactType,
                        'callStatus' => $detail->callStatus,
                        'contactPhoneNumber' => $detail->contactPhoneNumber,
                        'contactName' => $detail->contactName,
                        'contactRelation' => $detail->contactRelation,
                        'caseResultId' => $detail->caseResultId,
                        'caseResultName' => $detail->caseResult->caseResultName ?? null,
                        'promisedPaymentDate' => $detail->promisedPaymentDate?->format('Y-m-d'),
                        'promisedPaymentAmount' => $detail->promisedPaymentAmount,
                        'claimedPaymentDate' => $detail->claimedPaymentDate?->format('Y-m-d'),
                        'claimedPaymentAmount' => $detail->claimedPaymentAmount,
                        'dtCallStarted' => $detail->dtCallStarted?->utc()->format('Y-m-d\TH:i:s\Z'),
                        'dtCallEnded' => $detail->dtCallEnded?->utc()->format('Y-m-d\TH:i:s\Z'),
                        'isUncontactable' => $detail->isUncontactable,
                        'createdAt' => $detail->createdAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                        'createdBy' => $detail->createdBy,
                        'creator' => [
                            'id' => $detail->creator->id ?? null,
                            'username' => $detail->creator->username ?? null,
                            'userFullName' => $detail->creator->userFullName ?? null,
                        ],
                        'uploadedImages' => [],
                        '_source' => 'litigation'
                    ];
                });

                Log::info('Litigation remarks fetched', [
                    'count' => $litRemarks->count()
                ]);
            }

            // Step 3: Build Litigation phone collections info
            $litPhoneCollectionsInfo = $litPhoneCollections->map(function($pc) use ($litRemarks) {
                $pcRemarks = $litRemarks->where('litPhoneCollectionId', $pc->litPhoneCollectionId)->values();

                return [
                    'litPhoneCollectionId' => $pc->litPhoneCollectionId,
                    'phoneCollectionId' => null, // For compatibility
                    'customerFullName' => $pc->customerFullName,
                    'status' => $pc->status,
                    'totalAttempts' => $pc->totalAttempts,
                    'remarks' => $pcRemarks,
                    'remarkCount' => $pcRemarks->count(),
                    '_source' => 'litigation'
                ];
            });

            // Step 4: Merge all remarks
            $allRemarks = collect($ccRemarks['allRemarks'] ?? [])
                ->merge($litRemarks)
                ->sortByDesc('createdAt')
                ->values();

            // Step 5: Merge phone collections
            $allPhoneCollections = collect($ccRemarks['phoneCollections'] ?? [])
                ->merge($litPhoneCollectionsInfo)
                ->values();

            // Step 6: Calculate summary
            $summary = [
                'totalPhoneCollections' => $allPhoneCollections->count(),
                'totalRemarks' => $allRemarks->count(),
                'bySource' => [
                    'cc' => collect($ccRemarks['allRemarks'] ?? [])->count(),
                    'litigation' => $litRemarks->count(),
                ],
                'byContactType' => $allRemarks->groupBy('contactType')->map->count()->toArray(),
                'byCallStatus' => $allRemarks->groupBy('callStatus')->map->count()->toArray(),
            ];

            Log::info('Contract remarks merged successfully', [
                'contract_id' => $contractId,
                'total_remarks' => $allRemarks->count(),
                'cc_remarks' => collect($ccRemarks['allRemarks'] ?? [])->count(),
                'lit_remarks' => $litRemarks->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Contract remarks retrieved successfully',
                'data' => [
                    'contractId' => $contractId,
                    'phoneCollections' => $allPhoneCollections,
                    'allRemarks' => $allRemarks,
                    'summary' => $summary
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch contract remarks', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve contract remarks',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * GET /api/lit-phone-collection-details/case-results
     * Get all active case results for Litigation
     */
    public function getCaseResults(): JsonResponse
    {
        try {
            Log::info('Fetching Litigation case results');

            $caseResults = TblLitCaseResult::whereNull('deletedAt')
                ->where('caseResultActive', true)
                ->orderBy('caseResultName', 'asc')
                ->get()
                ->map(function ($result) {
                    return [
                        'caseResultId' => $result->caseResultId,
                        'caseResultName' => $result->caseResultName,
                        'caseResultRemark' => $result->caseResultRemark,
                        'caseResultActive' => $result->caseResultActive,
                        'requiredField' => $result->requiredField,
                        'createdAt' => $result->createdAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                        'updatedAt' => $result->updatedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                    ];
                });

            Log::info('Litigation case results fetched successfully', [
                'total' => $caseResults->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Litigation case results retrieved successfully',
                'total' => $caseResults->count(),
                'data' => $caseResults
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch Litigation case results', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve case results',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * POST /api/lit-phone-collection-details
     * Create a new Litigation phone collection detail record
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'litPhoneCollectionId' => 'required|integer|exists:tbl_LitPhoneCollection,litPhoneCollectionId',
                'contactType' => 'required|in:rpc,tpc',
                'phoneId' => 'nullable|integer',
                'contactDetailId' => 'nullable|integer',
                'contactPhoneNumber' => 'nullable|string|max:255',
                'contactName' => 'nullable|string|max:255',
                'contactRelation' => 'nullable|string|max:255',
                'callStatus' => 'required|in:reached,ring,busy,cancelled,power_off,wrong_number,no_contact',
                'caseResultId' => 'nullable|integer',
                'remark' => 'nullable|string',
                'promisedPaymentDate' => 'nullable|date_format:Y-m-d',
                'promisedPaymentAmount' => 'nullable|integer',
                'claimedPaymentDate' => 'nullable|date_format:Y-m-d',
                'claimedPaymentAmount' => 'nullable|integer',
                'dtCallStarted' => 'nullable|date_format:Y-m-d H:i:s',
                'dtCallEnded' => 'nullable|date_format:Y-m-d H:i:s',
                'isUncontactable' => 'nullable|boolean',
                'createdBy' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validatedData = $validator->validated();

            Log::info('Creating Litigation phone collection detail', [
                'lit_phone_collection_id' => $validatedData['litPhoneCollectionId'],
                'contact_type' => $validatedData['contactType'] ?? null,
                'call_status' => $validatedData['callStatus'] ?? null,
                'createdBy' => $validatedData['createdBy']
            ]);

            // Ensure user exists
            $this->userRefService->ensureUserExists($validatedData['createdBy']);

            DB::beginTransaction();

            // Create the record
            $phoneCollectionDetail = TblLitPhoneCollectionDetail::create([
                'litPhoneCollectionId' => $validatedData['litPhoneCollectionId'],
                'contactType' => $validatedData['contactType'],
                'phoneId' => $validatedData['phoneId'] ?? null,
                'contactDetailId' => $validatedData['contactDetailId'] ?? null,
                'contactPhoneNumber' => $validatedData['contactPhoneNumber'] ?? null,
                'contactName' => $validatedData['contactName'] ?? null,
                'contactRelation' => $validatedData['contactRelation'] ?? null,
                'callStatus' => $validatedData['callStatus'],
                'caseResultId' => $validatedData['caseResultId'] ?? null,
                'remark' => $validatedData['remark'] ?? null,
                'promisedPaymentDate' => $validatedData['promisedPaymentDate'] ?? null,
                'promisedPaymentAmount' => $validatedData['promisedPaymentAmount'] ?? null,
                'claimedPaymentDate' => $validatedData['claimedPaymentDate'] ?? null,
                'claimedPaymentAmount' => $validatedData['claimedPaymentAmount'] ?? null,
                'dtCallStarted' => $validatedData['dtCallStarted'] ?? null,
                'dtCallEnded' => $validatedData['dtCallEnded'] ?? null,
                'isUncontactable' => $validatedData['isUncontactable'] ?? false,
                'createdAt' => now(),
                'createdBy' => $validatedData['createdBy'],
            ]);

            // Update phone collection attempts count
            $phoneCollection = TblLitPhoneCollection::find($validatedData['litPhoneCollectionId']);

            if ($phoneCollection) {
                $phoneCollection->increment('totalAttempts');
                $phoneCollection->update([
                    'lastAttemptAt' => now(),
                    'lastAttemptBy' => $validatedData['createdBy'],
                    'updatedBy' => $validatedData['createdBy'],
                ]);

                Log::info('Updated Litigation phone collection attempts', [
                    'lit_phone_collection_id' => $phoneCollection->litPhoneCollectionId,
                    'total_attempts' => $phoneCollection->totalAttempts
                ]);
            }

            DB::commit();

            Log::info('Litigation phone collection detail created successfully', [
                'lit_phone_collection_detail_id' => $phoneCollectionDetail->litPhoneCollectionDetailId,
                'lit_phone_collection_id' => $validatedData['litPhoneCollectionId']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Litigation phone collection detail created successfully',
                'data' => [
                    'litPhoneCollectionDetailId' => $phoneCollectionDetail->litPhoneCollectionDetailId,
                    'litPhoneCollectionId' => $phoneCollectionDetail->litPhoneCollectionId,
                    'contactType' => $phoneCollectionDetail->contactType,
                    'phoneId' => $phoneCollectionDetail->phoneId,
                    'contactDetailId' => $phoneCollectionDetail->contactDetailId,
                    'contactPhoneNumber' => $phoneCollectionDetail->contactPhoneNumber,
                    'contactName' => $phoneCollectionDetail->contactName,
                    'contactRelation' => $phoneCollectionDetail->contactRelation,
                    'callStatus' => $phoneCollectionDetail->callStatus,
                    'caseResultId' => $phoneCollectionDetail->caseResultId,
                    'remark' => $phoneCollectionDetail->remark,
                    'promisedPaymentDate' => $phoneCollectionDetail->promisedPaymentDate?->format('Y-m-d'),
                    'promisedPaymentAmount' => $phoneCollectionDetail->promisedPaymentAmount,
                    'claimedPaymentDate' => $phoneCollectionDetail->claimedPaymentDate?->format('Y-m-d'),
                    'claimedPaymentAmount' => $phoneCollectionDetail->claimedPaymentAmount,
                    'dtCallStarted' => $phoneCollectionDetail->dtCallStarted?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'dtCallEnded' => $phoneCollectionDetail->dtCallEnded?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'isUncontactable' => $phoneCollectionDetail->isUncontactable,
                    'createdAt' => $phoneCollectionDetail->createdAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'createdBy' => $phoneCollectionDetail->createdBy,
                ]
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create Litigation phone collection detail', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create Litigation phone collection detail',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Fetch historical remarks from CC BE API
     */
    private function fetchCCHistoricalRemarks(int $contractId): array
    {
        try {
            $ccBeUrl = env('CC_BE_URL', 'http://cc-staging-be.mmapp.xyz');
            $url = "{$ccBeUrl}/api/cc-phone-collection-details/contract/{$contractId}/remarks";

            Log::info('Calling CC BE API for historical remarks', [
                'url' => $url,
                'contract_id' => $contractId
            ]);

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::warning('CC BE API returned non-successful status', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'phoneCollections' => [],
                    'allRemarks' => [],
                    'summary' => []
                ];
            }

            $data = $response->json();

            if (!isset($data['success']) || !$data['success']) {
                Log::warning('CC BE API returned unsuccessful response', [
                    'data' => $data
                ]);

                return [
                    'phoneCollections' => [],
                    'allRemarks' => [],
                    'summary' => []
                ];
            }

            Log::info('CC BE API call successful', [
                'total_remarks' => count($data['data']['allRemarks'] ?? [])
            ]);

            return $data['data'];

        } catch (Exception $e) {
            Log::error('Failed to call CC BE API', [
                'contract_id' => $contractId,
                'error' => $e->getMessage()
            ]);

            // Return empty data on error (fail gracefully)
            return [
                'phoneCollections' => [],
                'allRemarks' => [],
                'summary' => []
            ];
        }
    }
}
