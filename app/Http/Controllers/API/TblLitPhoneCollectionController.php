<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TblLitPhoneCollection;
use App\Models\TblLitCycle;
use App\Services\ExternalApiService;
use App\Services\UserReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class TblLitPhoneCollectionController extends Controller
{
    protected $userRefService;
    protected $externalApiService;

    public function __construct(
        UserReferenceService $userRefService,
        ExternalApiService $externalApiService
    ) {
        $this->userRefService = $userRefService;
        $this->externalApiService = $externalApiService;
    }

    /**
     * POST /api/lit/phone-collections
     * Create single or multiple phone collections
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validate input structure
            $validator = Validator::make($request->all(), [
                'user_id'                               => 'required|integer',
                'collections'                           => 'required|array|min:1',
                'collections.*.ltoAuthUserId'           => 'required|integer',
                'collections.*.litCaseId'               => 'nullable|integer',
                'collections.*.contractId'              => 'nullable|integer',
                'collections.*.customerId'              => 'nullable|integer',
                'collections.*.paymentId'               => 'nullable|integer',
                'collections.*.paymentNo'               => 'nullable|integer',
                'collections.*.assetId'                 => 'nullable|integer',
                'collections.*.dueDate'                 => 'nullable|date_format:Y-m-d',
                'collections.*.daysOverdueGross'        => 'nullable|integer',
                'collections.*.daysOverdueNet'          => 'nullable|integer',
                'collections.*.daysSinceLastPayment'    => 'nullable|integer',
                'collections.*.totalOvdAmount'          => 'nullable|integer',
                'collections.*.contractNo'              => 'nullable|string|max:255',
                'collections.*.contractDate'            => 'nullable|date_format:Y-m-d',
                'collections.*.contractType'            => 'nullable|string|max:255',
                'collections.*.contractingProductType'  => 'nullable|string|max:255',
                'collections.*.customerFullName'        => 'nullable|string|max:255',
                'collections.*.gender'                  => 'nullable|string|max:50',
                'collections.*.birthDate'               => 'nullable|date_format:Y-m-d',
                'collections.*.cycleId'                 => 'required|integer|exists:tbl_LitCycle,cycleId',
                'collections.*.hasKYCAppAccount'        => 'nullable|boolean',
                'collections.*.customerAge'             => 'nullable|integer',
                'collections.*.contractPlaceName'       => 'nullable|string|max:255',
                'collections.*.salesAreaName'           => 'nullable|string|max:255',
                'collections.*.productName'             => 'nullable|string|max:255',
                'collections.*.productColor'            => 'nullable|string|max:255',
                'collections.*.plateNo'                 => 'nullable|string|max:255',
                'collections.*.unitPrice'               => 'nullable|string|max:255',
                'collections.*.paymentStatus'           => 'nullable|string|max:255',
                'collections.*.phoneNo1'                => 'nullable|string|max:255',
                'collections.*.phoneNo2'                => 'nullable|string|max:255',
                'collections.*.phoneNo3'                => 'nullable|string|max:255',
                'collections.*.homeAddress'             => 'nullable|string|max:500',
                'collections.*.salesAreaId'             => 'nullable|integer',
                'collections.*.contractPlaceId'         => 'nullable|integer',
                'collections.*.lastPaymentDate'         => 'nullable|date_format:Y-m-d',
                'collections.*.beelineDistance'         => 'nullable|numeric',
                'collections.*.deviceControlProvider'   => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors()
                ], 422);
            }

            // Ensure user exists
            $this->userRefService->ensureUserExists($request->user_id);

            // Pre-load controller mapping per (ltoId, cycleId) for round-robin
            // Structure: [ "$ltoId-$cycleId" => [ controllerId, ... ] ]
            $roundRobinCounters = [];

            DB::beginTransaction();

            $created = [];
            $errors  = [];

            foreach ($request->collections as $index => $collectionData) {
                try {
                    $ltoAuthUserId = $collectionData['ltoAuthUserId'];
                    $cycleId       = $collectionData['cycleId'];

                    // Resolve controllerId via tbl_LitControllerLto
                    $cacheKey    = "{$ltoAuthUserId}-{$cycleId}";
                    $controllers = \App\Models\TblLitControllerLto::where('ltoId', $ltoAuthUserId)
                        ->where('cycleId', $cycleId)
                        ->where('active', true)
                        ->orderBy('id')
                        ->pluck('controllerId')
                        ->toArray();

                    $assignedTo = null;
                    if (!empty($controllers)) {
                        $counter    = $roundRobinCounters[$cacheKey] ?? 0;
                        $assignedTo = $controllers[$counter % count($controllers)];
                        $roundRobinCounters[$cacheKey] = $counter + 1;
                    }

                    $data = [
                        'litCaseId'              => $collectionData['litCaseId'] ?? null,
                        'status'                 => 'pending',
                        'contractId'             => $collectionData['contractId'] ?? null,
                        'customerId'             => $collectionData['customerId'] ?? null,
                        'paymentId'              => $collectionData['paymentId'] ?? null,
                        'paymentNo'              => $collectionData['paymentNo'] ?? null,
                        'assetId'               => $collectionData['assetId'] ?? null,
                        'dueDate'               => $collectionData['dueDate'] ?? null,
                        'daysOverdueGross'       => $collectionData['daysOverdueGross'] ?? null,
                        'daysOverdueNet'         => $collectionData['daysOverdueNet'] ?? null,
                        'daysSinceLastPayment'   => $collectionData['daysSinceLastPayment'] ?? null,
                        'totalOvdAmount'         => $collectionData['totalOvdAmount'] ?? null,
                        'contractNo'             => $collectionData['contractNo'] ?? null,
                        'contractDate'           => $collectionData['contractDate'] ?? null,
                        'contractType'           => $collectionData['contractType'] ?? null,
                        'contractingProductType' => $collectionData['contractingProductType'] ?? null,
                        'customerFullName'       => $collectionData['customerFullName'] ?? null,
                        'gender'                => $collectionData['gender'] ?? null,
                        'birthDate'             => $collectionData['birthDate'] ?? null,
                        'cycleId'               => $cycleId,
                        'hasKYCAppAccount'       => $collectionData['hasKYCAppAccount'] ?? false,
                        'customerAge'            => $collectionData['customerAge'] ?? null,
                        'contractPlaceName'      => $collectionData['contractPlaceName'] ?? null,
                        'salesAreaName'          => $collectionData['salesAreaName'] ?? null,
                        'productName'            => $collectionData['productName'] ?? null,
                        'productColor'           => $collectionData['productColor'] ?? null,
                        'plateNo'               => $collectionData['plateNo'] ?? null,
                        'unitPrice'             => $collectionData['unitPrice'] ?? null,
                        'paymentStatus'          => $collectionData['paymentStatus'] ?? null,
                        'phoneNo1'              => $collectionData['phoneNo1'] ?? null,
                        'phoneNo2'              => $collectionData['phoneNo2'] ?? null,
                        'phoneNo3'              => $collectionData['phoneNo3'] ?? null,
                        'homeAddress'           => $collectionData['homeAddress'] ?? null,
                        'salesAreaId'            => $collectionData['salesAreaId'] ?? null,
                        'contractPlaceId'        => $collectionData['contractPlaceId'] ?? null,
                        'lastPaymentDate'        => $collectionData['lastPaymentDate'] ?? null,
                        'beelineDistance'        => $collectionData['beelineDistance'] ?? null,
                        'deviceControlProvider'  => $collectionData['deviceControlProvider'] ?? null,

                        // Assignment
                        'assignedTo'   => $assignedTo,
                        'assignedFrom' => $ltoAuthUserId,
                        'assignedBy'   => null,
                        'assignedAt'   => null,

                        // System fields
                        'totalAttempts'  => 0,
                        'lastAttemptAt'  => null,
                        'lastAttemptBy'  => null,
                        'createdAt'      => now()->timezone('Asia/Yangon'),
                        'createdBy'      => $request->user_id,
                        'updatedAt'      => null,
                        'updatedBy'      => null,
                        'deletedAt'      => null,
                        'deletedBy'      => null,
                        'deletedReason'  => null,
                        'completedBy'    => null,
                        'completedAt'    => null,
                    ];

                    $collection = TblLitPhoneCollection::create($data);
                    $created[]  = $collection;

                } catch (Exception $e) {
                    $errors[] = [
                        'index'      => $index,
                        'contractNo' => $collectionData['contractNo'] ?? 'unknown',
                        'error'      => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            Log::info('Litigation phone collections created', [
                'total_requested' => count($request->collections),
                'created'         => count($created),
                'failed'          => count($errors),
                'created_by'      => $request->user_id
            ]);

            $response = [
                'success' => true,
                'message' => 'Litigation phone collections created',
                'summary' => [
                    'total_requested' => count($request->collections),
                    'created'         => count($created),
                    'failed'          => count($errors)
                ],
                'data' => $created
            ];

            if (!empty($errors)) {
                $response['errors'] = $errors;
            }

            return response()->json($response, 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create litigation phone collections', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create litigation phone collections',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * GET /api/lit/phone-collections
     * Get litigation phone collections with filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'nullable|string',
                'assignedTo' => 'nullable|integer',
                'cycleId' => 'nullable|integer',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
                'assignedAtFrom' => 'nullable|date_format:Y-m-d',
                'assignedAtTo' => 'nullable|date_format:Y-m-d|after_or_equal:assignedAtFrom',
                'customerFullName' => 'nullable|string',
                'contractNo' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = TblLitPhoneCollection::notDeleted();

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by assignedTo
            if ($request->has('assignedTo')) {
                $query->where('assignedTo', $request->assignedTo);
            }

            // Filter by cycleId
            if ($request->has('cycleId')) {
                $query->where('cycleId', $request->cycleId);
            }

            // Filter by assignedAt date range
            if ($request->has('assignedAtFrom')) {
                $assignedAtFrom = $request->assignedAtFrom . ' 00:00:00';
                $query->where('assignedAt', '>=', $assignedAtFrom);
            }

            if ($request->has('assignedAtTo')) {
                $assignedAtTo = $request->assignedAtTo . ' 23:59:59';
                $query->where('assignedAt', '<=', $assignedAtTo);
            }

            // Filter by customerFullName (partial match)
            if ($request->has('customerFullName')) {
                $query->where('customerFullName', 'ILIKE', '%' . $request->customerFullName . '%');
            }

            // Filter by contractNo (exact or partial match)
            if ($request->has('contractNo')) {
                $query->where('contractNo', 'ILIKE', '%' . $request->contractNo . '%');
            }

            // Get total before pagination
            $total = $query->count();

            // Pagination
            $perPage = $request->input('per_page', 20);
            $page = $request->input('page', 1);

            $collections = $query
                ->with([
                    'cycle:cycleId,cycleName',
                    'assignedToUser:authUserId,userFullName',
                    'assignedByUser:authUserId,userFullName',
                    'creator:authUserId,userFullName',
                    'updater:authUserId,userFullName'
                ])
                ->orderBy('litPhoneCollectionId', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            // Map to add user full names
            $collections->each(function($collection) {
                $collection->cycleName = $collection->cycle->cycleName ?? null;
                $collection->assignedToUserFullName = $collection->assignedToUser->userFullName ?? null;
                $collection->assignedByUserFullName = $collection->assignedByUser->userFullName ?? null;
                $collection->createdByUserFullName = $collection->creator->userFullName ?? null;
                $collection->updatedByUserFullName = $collection->updater->userFullName ?? null;

                unset(
                    $collection->cycle,
                    $collection->assignedToUser,
                    $collection->assignedByUser,
                    $collection->creator,
                    $collection->updater
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Litigation phone collections retrieved successfully',
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => ceil($total / $perPage),
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $total)
                ],
                'data' => $collections
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve litigation phone collections', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve litigation phone collections',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * GET /api/lit/phone-collections/{litPhoneCollectionId}/contract
     * Get contract details by litPhoneCollectionId
     */
    public function getContractDetails(int $litPhoneCollectionId): JsonResponse
    {
        try {
            // Validate litPhoneCollectionId
            if ($litPhoneCollectionId <= 0) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Invalid litigation phone collection ID. ID must be a positive integer.',
                    'data' => null
                ], 400);
            }

            Log::info('Looking up litigation contract by litPhoneCollectionId', [
                'lit_phone_collection_id' => $litPhoneCollectionId
            ]);

            // Find the phone collection record
            $phoneCollection = TblLitPhoneCollection::find($litPhoneCollectionId);

            if (!$phoneCollection) {
                Log::warning('Litigation phone collection record not found', [
                    'lit_phone_collection_id' => $litPhoneCollectionId
                ]);

                return response()->json([
                    'status' => 0,
                    'message' => 'Litigation phone collection record not found.',
                    'data' => null
                ], 404);
            }

            $contractId = $phoneCollection->contractId;

            Log::info('Found litigation phone collection record', [
                'lit_phone_collection_id' => $litPhoneCollectionId,
                'contract_id' => $contractId,
                'customer_name' => $phoneCollection->customerFullName
            ]);

            // Fetch contract details from external API using contractId
            $externalApiService = app(\App\Services\ExternalApiService::class);
            $contractData = $externalApiService->fetchContractDetails($contractId);

            // If external API failed
            if (!isset($contractData['status']) || $contractData['status'] !== 1) {
                return response()->json($contractData, 200);
            }

            // Combine phone collection data with contract data
            $combinedData = [
                'phone_collection' => [
                    'litPhoneCollectionId' => $phoneCollection->litPhoneCollectionId,
                    'litCaseId' => $phoneCollection->litCaseId,
                    'status' => $phoneCollection->status,
                    'assignedTo' => $phoneCollection->assignedTo,
                    'assignedBy' => $phoneCollection->assignedBy,
                    'assignedAt' => $phoneCollection->assignedAt,
                    'totalAttempts' => $phoneCollection->totalAttempts,
                    'lastAttemptAt' => $phoneCollection->lastAttemptAt,
                    'lastAttemptBy' => $phoneCollection->lastAttemptBy,
                    'cycleId' => $phoneCollection->cycleId,
                    'dueDate' => $phoneCollection->dueDate,
                    'daysOverdueGross' => $phoneCollection->daysOverdueGross,
                    'daysOverdueNet' => $phoneCollection->daysOverdueNet,
                    'totalOvdAmount' => $phoneCollection->totalOvdAmount,
                    'paymentStatus' => $phoneCollection->paymentStatus,
                    'completedBy' => $phoneCollection->completedBy,
                    'completedAt' => $phoneCollection->completedAt,
                ],
                'contract' => $contractData['data']
            ];

            return response()->json([
                'status' => 1,
                'data' => $combinedData,
                'message' => 'Litigation phone collection with contract details retrieved successfully.'
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch litigation phone collection with contract details', [
                'lit_phone_collection_id' => $litPhoneCollectionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 0,
                'message' => 'Unable to fetch litigation phone collection with contract details.',
                'data' => null
            ], 500);
        }
    }

    /**
     * GET /api/lit/phone-collections/{litPhoneCollectionId}/payment-info
     * Get payment information for litigation phone collection
     */
    public function getPaymentInfo(int $litPhoneCollectionId): JsonResponse
    {
        try {
            // Validate litPhoneCollectionId
            if ($litPhoneCollectionId <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid litigation phone collection ID',
                    'error' => 'ID must be a positive integer'
                ], 400);
            }

            Log::info('Fetching payment info for litigation phone collection', [
                'lit_phone_collection_id' => $litPhoneCollectionId
            ]);

            // Find litigation phone collection
            $litPhoneCollection = TblLitPhoneCollection::find($litPhoneCollectionId);

            if (!$litPhoneCollection) {
                return response()->json([
                    'success' => false,
                    'message' => 'Litigation phone collection not found',
                ], 404);
            }

            // Prepare response data
            $paymentInfo = [
                'litPhoneCollectionId' => $litPhoneCollection->litPhoneCollectionId,
                'dueDate' => $litPhoneCollection->dueDate?->format('Y-m-d'),
                'paymentNo' => $litPhoneCollection->paymentNo,
                'daysOverdueGross' => $litPhoneCollection->daysOverdueGross,
                'daysOverdueNet' => $litPhoneCollection->daysOverdueNet,
                'daysSinceLastPayment' => $litPhoneCollection->daysSinceLastPayment,
                'lastPaymentDate' => $litPhoneCollection->lastPaymentDate?->format('Y-m-d'),
                'totalOvdAmount' => $litPhoneCollection->totalOvdAmount,
                'paymentStatus' => $litPhoneCollection->paymentStatus,
            ];

            Log::info('Litigation payment info retrieved successfully', [
                'lit_phone_collection_id' => $litPhoneCollectionId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Litigation payment information retrieved successfully',
                'data' => $paymentInfo
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch litigation payment info', [
                'lit_phone_collection_id' => $litPhoneCollectionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve litigation payment information',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * PATCH /api/lit-phone-collections/{litPhoneCollectionId}/complete
     * Mark a litigation phone collection as completed
     */
    public function markAsCompleted(Request $request, int $litPhoneCollectionId): JsonResponse
    {
        try {
            Log::info('Marking Litigation phone collection as completed', [
                'lit_phone_collection_id' => $litPhoneCollectionId
            ]);

            // Validate request
            $validator = Validator::make($request->all(), [
                'completedBy' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $completedBy = $request->input('completedBy');

            // Find phone collection
            $phoneCollection = TblLitPhoneCollection::find($litPhoneCollectionId);

            if (!$phoneCollection) {
                return response()->json([
                    'success' => false,
                    'message' => 'Litigation phone collection not found',
                ], 404);
            }

            // Check if already completed
            if ($phoneCollection->status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone collection is already completed',
                    'data' => [
                        'litPhoneCollectionId' => $phoneCollection->litPhoneCollectionId,
                        'status' => $phoneCollection->status,
                        'completedBy' => $phoneCollection->completedBy,
                        'completedAt' => $phoneCollection->completedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                    ]
                ], 400);
            }

            // Ensure user exists
            $this->userRefService->ensureUserExists($completedBy);

            // Update status to completed
            $phoneCollection->update([
                'status' => 'completed',
                'completedBy' => $completedBy,
                'completedAt' => now()->timezone('Asia/Yangon'),
                'updatedBy' => $completedBy,
                'updatedAt' => now()->timezone('Asia/Yangon'),
            ]);

            Log::info('Litigation phone collection marked as completed', [
                'lit_phone_collection_id' => $litPhoneCollectionId,
                'completed_by' => $completedBy
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Phone collection marked as completed successfully',
                'data' => [
                    'litPhoneCollectionId' => $phoneCollection->litPhoneCollectionId,
                    'litCaseId' => $phoneCollection->litCaseId,
                    'contractId' => $phoneCollection->contractId,
                    'customerFullName' => $phoneCollection->customerFullName,
                    'status' => $phoneCollection->status,
                    'totalAttempts' => $phoneCollection->totalAttempts,
                    'completedBy' => $phoneCollection->completedBy,
                    'completedAt' => $phoneCollection->completedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'updatedAt' => $phoneCollection->updatedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to mark Litigation phone collection as completed', [
                'lit_phone_collection_id' => $litPhoneCollectionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark phone collection as completed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Export Litigation daily call report
     * POST /api/lit-phone-collections/export-daily-call-report
     *
     * Body: {
     *   "from": "2026-01-23",
     *   "to": "2026-01-25",
     *   "cycleId": 3,
     *   "emails": ["user1@example.com", "user2@example.com"]
     * }
     */
    public function exportDailyCallReport(\App\Http\Requests\ExportLitDailyCallRequest $request): JsonResponse
    {
        try {
            $fromDate = $request->input('from');
            $toDate = $request->input('to');
            $cycleId = $request->input('cycleId');
            $emails = $request->input('emails');

            // Get authenticated user if available
            $requestedBy = $request->user()?->authUserId ?? null;

            Log::info('Queueing Litigation daily call export job', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'cycle_id' => $cycleId,
                'emails' => $emails,
                'requested_by' => $requestedBy
            ]);

            // Dispatch job to queue
            \App\Jobs\ExportLitDailyCallReportJob::dispatch(
                $fromDate,
                $toDate,
                $cycleId,
                $emails,
                $requestedBy
            )
            ->onConnection('database')  // ← THÊM DÒNG NÀY
            ->onQueue('exports');       // ← THÊM DÒNG NÀY

            return response()->json([
                'success' => true,
                'message' => 'Litigation daily call export request queued successfully. You will receive the report via email shortly.',
                'data' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'cycle_id' => $cycleId,
                    'emails' => $emails,
                    'status' => 'queued'
                ]
            ], 202);

        } catch (\Exception $e) {
            Log::error('Failed to queue Litigation daily call export request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to queue Litigation daily call export request',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Export Litigation call assign report
     * POST /api/lit-phone-collections/export-call-assign-report
     *
     * Body: {
     *   "from": "2026-01-01",
     *   "to": "2026-01-31",
     *   "cycleId": 3,
     *   "emails": ["user1@example.com", "user2@example.com"]
     * }
     */
    public function exportCallAssignReport(\App\Http\Requests\ExportLitCallAssignRequest $request): JsonResponse
    {
        try {
            $fromDate = $request->input('from');
            $toDate = $request->input('to');
            $cycleId = $request->input('cycleId');
            $emails = $request->input('emails');

            // Get authenticated user if available
            $requestedBy = $request->user()?->authUserId ?? null;

            Log::info('Queueing Litigation call assign export job', [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'cycle_id' => $cycleId,
                'emails' => $emails,
                'requested_by' => $requestedBy
            ]);

            // Dispatch job to queue
            \App\Jobs\ExportLitCallAssignReportJob::dispatch(
                $fromDate,
                $toDate,
                $cycleId,
                $emails,
                $requestedBy
            )
            ->onConnection('database')
            ->onQueue('exports');

            return response()->json([
                'success' => true,
                'message' => 'Litigation call assign export request queued successfully. You will receive the report via email shortly.',
                'data' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'cycle_id' => $cycleId,
                    'emails' => $emails,
                    'status' => 'queued'
                ]
            ], 202);

        } catch (\Exception $e) {
            Log::error('Failed to queue Litigation call assign export request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to queue Litigation call assign export request',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
