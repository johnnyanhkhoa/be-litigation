<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TblLitCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;
use App\Services\UserReferenceService;

class TblLitCycleController extends Controller
{
    protected $userRefService;

    public function __construct(UserReferenceService $userRefService)
    {
        $this->userRefService = $userRefService;
    }

    /**
     * GET /api/lit/cycles
     * Get all litigation cycles
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = TblLitCycle::notDeleted();

            // Filter by active status
            if ($request->has('cycleActive')) {
                $query->where('cycleActive', $request->boolean('cycleActive'));
            }

            // Filter by write-off active
            if ($request->has('cycleWriteOffActive')) {
                $query->where('cycleWriteOffActive', $request->boolean('cycleWriteOffActive'));
            }

            // Filter by AMC contract active
            if ($request->has('cycleAmcContractActive')) {
                $query->where('cycleAmcContractActive', $request->boolean('cycleAmcContractActive'));
            }

            $cycles = $query->with([
                'creator:id,authUserId,userFullName',
                'updater:id,authUserId,userFullName',
                'deactivator:id,authUserId,userFullName',
                'deleter:id,authUserId,userFullName'
            ])->orderBy('cycleName')->get();

            // Map to add user full names
            $cycles->each(function($cycle) {
                $cycle->createdByUserFullName = $cycle->creator->userFullName ?? null;
                $cycle->updatedByUserFullName = $cycle->updater->userFullName ?? null;
                $cycle->deactivatedByUserFullName = $cycle->deactivator->userFullName ?? null;
                $cycle->deletedByUserFullName = $cycle->deleter->userFullName ?? null;

                // Remove relationship objects
                unset($cycle->creator, $cycle->updater, $cycle->deactivator, $cycle->deleter);
            });

            return response()->json([
                'success' => true,
                'message' => 'Litigation cycles retrieved successfully',
                'total' => $cycles->count(),
                'data' => $cycles
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve litigation cycles', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve litigation cycles',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * GET /api/lit/cycles/{id}
     * Get litigation cycle detail
     */
    public function show(int $id): JsonResponse
    {
        try {
            $cycle = TblLitCycle::notDeleted()
            ->with([
                'creator:id,authUserId,userFullName',
                'updater:id,authUserId,userFullName',
                'deactivator:id,authUserId,userFullName',
                'deleter:id,authUserId,userFullName'
            ])
            ->find($id);

            if (!$cycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Litigation cycle not found'
                ], 404);
            }

            // Add user full names
            $cycle->createdByUserFullName = $cycle->creator->userFullName ?? null;
            $cycle->updatedByUserFullName = $cycle->updater->userFullName ?? null;
            $cycle->deactivatedByUserFullName = $cycle->deactivator->userFullName ?? null;
            $cycle->deletedByUserFullName = $cycle->deleter->userFullName ?? null;

            unset($cycle->creator, $cycle->updater, $cycle->deactivator, $cycle->deleter);

            return response()->json([
                'success' => true,
                'message' => 'Litigation cycle retrieved successfully',
                'data' => $cycle
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve litigation cycle', [
                'cycle_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve litigation cycle',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * POST /api/lit/cycles
     * Create new litigation cycle
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'cycleName' => 'required|string|max:255',
                'cycleDateFrom' => 'required|date_format:Y-m-d',
                'cycleDateTo' => 'required|date_format:Y-m-d|after_or_equal:cycleDateFrom',
                'cycleWriteOffActive' => 'boolean',
                'cycleAmcContractActive' => 'boolean',
                'cycleRemark' => 'nullable|string',
                'user_id' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Ensure user exists in user_references
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            // Create new cycle (inactive by default)
            $cycle = TblLitCycle::create([
                'cycleName' => $request->cycleName,
                'cycleDateFrom' => $request->cycleDateFrom,
                'cycleDateTo' => $request->cycleDateTo,
                'cycleActive' => false,
                'cycleWriteOffActive' => $request->boolean('cycleWriteOffActive', false),
                'cycleAmcContractActive' => $request->boolean('cycleAmcContractActive', false),
                'cycleRemark' => $request->cycleRemark,
                'createdAt' => now(),
                'createdBy' => $request->user_id
            ]);

            DB::commit();

            Log::info('Litigation cycle created', [
                'cycle_id' => $cycle->cycleId,
                'cycle_name' => $cycle->cycleName,
                'created_by' => $request->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Litigation cycle created successfully',
                'data' => $cycle
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create litigation cycle', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create litigation cycle',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * PUT /api/lit/cycles/{id}
     * Update litigation cycle
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $cycle = TblLitCycle::notDeleted()->find($id);

            if (!$cycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Litigation cycle not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'cycleName' => 'required|string|max:255',
                'cycleDateFrom' => 'required|date_format:Y-m-d',
                'cycleDateTo' => 'required|date_format:Y-m-d|after_or_equal:cycleDateFrom',
                'cycleWriteOffActive' => 'boolean',
                'cycleAmcContractActive' => 'boolean',
                'cycleRemark' => 'nullable|string',
                'user_id' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Ensure user exists in user_references
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            $updateData = [
                'cycleName' => $request->cycleName,
                'cycleDateFrom' => $request->cycleDateFrom,
                'cycleDateTo' => $request->cycleDateTo,
                'cycleRemark' => $request->cycleRemark,
                'updatedAt' => now(),
                'updatedBy' => $request->user_id
            ];

            // Handle cycleWriteOffActive deactivation
            if ($cycle->cycleWriteOffActive && !$request->boolean('cycleWriteOffActive', false)) {
                // Đang active → chuyển sang inactive
                $updateData['cycleWriteOffActive'] = false;
                $updateData['deactivatedAt'] = now();
                $updateData['deactivatedBy'] = $request->user_id;
            } else {
                $updateData['cycleWriteOffActive'] = $request->boolean('cycleWriteOffActive', false);
            }

            // Handle cycleAmcContractActive (tương tự)
            if ($cycle->cycleAmcContractActive && !$request->boolean('cycleAmcContractActive', false)) {
                $updateData['cycleAmcContractActive'] = false;
                if (!isset($updateData['deactivatedAt'])) {
                    $updateData['deactivatedAt'] = now();
                    $updateData['deactivatedBy'] = $request->user_id;
                }
            } else {
                $updateData['cycleAmcContractActive'] = $request->boolean('cycleAmcContractActive', false);
            }

            $cycle->update($updateData);

            DB::commit();

            Log::info('Litigation cycle updated', [
                'cycle_id' => $id,
                'updated_by' => $request->user_id
            ]);

            // Load relationships for response
            $cycle->load([
                'creator:id,authUserId,userFullName',
                'updater:id,authUserId,userFullName',
                'deactivator:id,authUserId,userFullName',
                'deleter:id,authUserId,userFullName'
            ]);

            // Add user full names
            $cycle->createdByUserFullName = $cycle->creator->userFullName ?? null;
            $cycle->updatedByUserFullName = $cycle->updater->userFullName ?? null;
            $cycle->deactivatedByUserFullName = $cycle->deactivator->userFullName ?? null;
            $cycle->deletedByUserFullName = $cycle->deleter->userFullName ?? null;

            unset($cycle->creator, $cycle->updater, $cycle->deactivator, $cycle->deleter);

            return response()->json([
                'success' => true,
                'message' => 'Litigation cycle updated successfully',
                'data' => $cycle
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to update litigation cycle', [
                'cycle_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update litigation cycle',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * PATCH /api/lit/cycles/{id}/activate
     * Activate litigation cycle (deactivate all others)
     */
    public function activate(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $cycle = TblLitCycle::notDeleted()->find($id);

            if (!$cycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Litigation cycle not found'
                ], 404);
            }

            // Ensure user exists in user_references
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            // Deactivate all other active cycles
            $otherActiveCycles = TblLitCycle::notDeleted()
                ->where('cycleActive', true)
                ->where('cycleId', '!=', $id)
                ->get();

            foreach ($otherActiveCycles as $otherCycle) {
                $otherCycle->update([
                    'cycleActive' => false,
                    'deactivatedAt' => now(),
                    'deactivatedBy' => $request->user_id,
                    'deactivatedReason' => "Auto-deactivated when activating cycle {$id}",
                    'updatedAt' => now(),
                    'updatedBy' => $request->user_id
                ]);
            }

            Log::info('All litigation cycles deactivated before activating new cycle', [
                'deactivated_by' => $request->user_id
            ]);

            // Activate this cycle
            $cycle->update([
                'cycleActive' => true,
                'deactivatedAt' => null,
                'deactivatedBy' => null,
                'deactivatedReason' => null,  // ← THÊM dòng này
                'updatedAt' => now(),
                'updatedBy' => $request->user_id
            ]);

            DB::commit();

            Log::info('Litigation cycle activated', [
                'cycle_id' => $id,
                'activated_by' => $request->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Litigation cycle activated successfully',
                'data' => $cycle
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to activate litigation cycle', [
                'cycle_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to activate litigation cycle',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * PATCH /api/lit/cycles/{id}/deactivate
     * Deactivate litigation cycle
     */
    public function deactivate(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required',
                'deactivatedReason' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $cycle = TblLitCycle::notDeleted()->find($id);

            if (!$cycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Litigation cycle not found'
                ], 404);
            }

            // Ensure user exists in user_references
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            $cycle->update([
                'cycleActive' => false,
                'deactivatedAt' => now(),
                'deactivatedBy' => $request->user_id,
                'deactivatedReason' => $request->deactivatedReason,  // ← THÊM dòng này
                'updatedAt' => now(),
                'updatedBy' => $request->user_id
            ]);

            DB::commit();

            Log::info('Litigation cycle deactivated', [
                'cycle_id' => $id,
                'deactivated_by' => $request->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Litigation cycle deactivated successfully',
                'data' => $cycle
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to deactivate litigation cycle', [
                'cycle_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate litigation cycle',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * DELETE /api/lit/cycles/{id}
     * Soft delete litigation cycle
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $cycle = TblLitCycle::notDeleted()->find($id);

            if (!$cycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Litigation cycle not found'
                ], 404);
            }

            // Ensure user exists in user_references
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            $cycle->deletedAt = now();
            $cycle->deletedBy = $request->user_id;
            $cycle->save();

            DB::commit();

            Log::info('Litigation cycle deleted', [
                'cycle_id' => $id,
                'deleted_by' => $request->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Litigation cycle deleted successfully'
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete litigation cycle', [
                'cycle_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete litigation cycle',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
