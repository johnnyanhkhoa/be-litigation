<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TblLitControllerLto;
use App\Models\TblLitCycle;
use App\Models\UserReference;
use App\Services\UserReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class TblLitControllerLtoController extends Controller
{
    protected $userRefService;

    public function __construct(UserReferenceService $userRefService)
    {
        $this->userRefService = $userRefService;
    }

    /**
     * GET /api/lit/controller-assignments
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = TblLitControllerLto::notDeleted();

            // Filter by cycleId
            if ($request->has('cycleId')) {
                $query->forCycle($request->cycleId);
            }

            // Filter by active
            if ($request->has('active')) {
                $query->where('active', $request->boolean('active'));
            }

            // Filter by controllerId
            if ($request->has('controllerId')) {
                $query->where('controllerId', $request->controllerId);
            }

            // Filter by ltoId
            if ($request->has('ltoId')) {
                $query->where('ltoId', $request->ltoId);
            }

            $assignments = $query->with([
                'cycle:cycleId,cycleName',
                'controller:authUserId,userFullName',
                'lto:authUserId,userFullName',
                'creator:authUserId,userFullName',
                'updater:authUserId,userFullName'
            ])->orderBy('id', 'desc')->get();

            // Map to add user full names
            $assignments->each(function($assignment) {
                $assignment->cycleName = $assignment->cycle->cycleName ?? null;
                $assignment->controllerName = $assignment->controller->userFullName ?? null;
                $assignment->ltoName = $assignment->lto->userFullName ?? null;
                $assignment->createdByUserFullName = $assignment->creator->userFullName ?? null;
                $assignment->updatedByUserFullName = $assignment->updater->userFullName ?? null;

                unset($assignment->cycle, $assignment->controller, $assignment->lto, $assignment->creator, $assignment->updater);
            });

            return response()->json([
                'success' => true,
                'message' => 'Controller assignments retrieved successfully',
                'total' => $assignments->count(),
                'data' => $assignments
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve controller assignments', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve controller assignments',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * GET /api/lit/controller-assignments/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $assignment = TblLitControllerLto::notDeleted()
                ->with([
                    'cycle:cycleId,cycleName',
                    'controller:authUserId,userFullName,email',
                    'lto:authUserId,userFullName,email',
                    'creator:authUserId,userFullName',
                    'updater:authUserId,userFullName'
                ])
                ->find($id);

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controller assignment not found'
                ], 404);
            }

            // Add user full names
            $assignment->cycleName = $assignment->cycle->cycleName ?? null;
            $assignment->controllerName = $assignment->controller->userFullName ?? null;
            $assignment->controllerEmail = $assignment->controller->email ?? null;
            $assignment->ltoName = $assignment->lto->userFullName ?? null;
            $assignment->ltoEmail = $assignment->lto->email ?? null;
            $assignment->createdByUserFullName = $assignment->creator->userFullName ?? null;
            $assignment->updatedByUserFullName = $assignment->updater->userFullName ?? null;

            unset($assignment->cycle, $assignment->controller, $assignment->lto, $assignment->creator, $assignment->updater);

            return response()->json([
                'success' => true,
                'message' => 'Controller assignment retrieved successfully',
                'data' => $assignment
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve controller assignment', [
                'assignment_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve controller assignment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * POST /api/lit/controller-assignments
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'cycleId' => 'required|integer|exists:tbl_LitCycle,cycleId',
                'controllerId' => 'required|integer',
                'ltoId' => 'required|integer',
                'active' => 'boolean',
                'remark' => 'nullable|string',
                'user_id' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Ensure users exist
            $this->userRefService->ensureUserExists($request->controllerId);
            $this->userRefService->ensureUserExists($request->ltoId);
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            // Check duplicate
            $exists = TblLitControllerLto::notDeleted()
                ->where('cycleId', $request->cycleId)
                ->where('controllerId', $request->controllerId)
                ->where('ltoId', $request->ltoId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'This controller-LTO assignment already exists for this cycle'
                ], 422);
            }

            $assignment = TblLitControllerLto::create([
                'cycleId' => $request->cycleId,
                'controllerId' => $request->controllerId,
                'ltoId' => $request->ltoId,
                'active' => $request->boolean('active', true),
                'remark' => $request->remark,
                'createdAt' => now(),
                'createdBy' => $request->user_id
            ]);

            DB::commit();

            Log::info('Controller assignment created', [
                'assignment_id' => $assignment->id,
                'created_by' => $request->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controller assignment created successfully',
                'data' => $assignment
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create controller assignment', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create controller assignment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * PUT /api/lit/controller-assignments/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $assignment = TblLitControllerLto::notDeleted()->find($id);

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controller assignment not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'remark' => 'nullable|string',
                'active' => 'boolean',
                'user_id' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Ensure user exists
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            $assignment->update([
                'active' => $request->boolean('active', $assignment->active),
                'remark' => $request->remark ?? $assignment->remark,
                'updatedAt' => now(),
                'updatedBy' => $request->user_id
            ]);

            DB::commit();

            Log::info('Controller assignment updated', [
                'assignment_id' => $id,
                'updated_by' => $request->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controller assignment updated successfully',
                'data' => $assignment
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to update controller assignment', [
                'assignment_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update controller assignment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * PATCH /api/lit/controller-assignments/{id}/toggle-active
     */
    public function toggleActive(Request $request, int $id): JsonResponse
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

            $assignment = TblLitControllerLto::notDeleted()->find($id);

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controller assignment not found'
                ], 404);
            }

            // Ensure user exists
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            $assignment->update([
                'active' => !$assignment->active,
                'updatedAt' => now(),
                'updatedBy' => $request->user_id
            ]);

            DB::commit();

            Log::info('Controller assignment active toggled', [
                'assignment_id' => $id,
                'active' => $assignment->active,
                'updated_by' => $request->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controller assignment status toggled successfully',
                'data' => $assignment
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to toggle controller assignment', [
                'assignment_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle controller assignment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * DELETE /api/lit/controller-assignments/{id}
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

            $assignment = TblLitControllerLto::notDeleted()->find($id);

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Controller assignment not found'
                ], 404);
            }

            // Ensure user exists
            $this->userRefService->ensureUserExists($request->user_id);

            DB::beginTransaction();

            $assignment->deletedAt = now();
            $assignment->deletedBy = $request->user_id;
            $assignment->save();

            DB::commit();

            Log::info('Controller assignment deleted', [
                'assignment_id' => $id,
                'deleted_by' => $request->user_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Controller assignment deleted successfully'
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete controller assignment', [
                'assignment_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete controller assignment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
