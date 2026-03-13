<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TblLitCycle;
use App\Models\TblLitPhoneCollection;
use App\Models\TblLitPhoneCollectionDetail;
use App\Models\UserReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class LitMonitoringController extends Controller
{
    /**
     * Monitor single Litigation Controller performance
     * GET /api/monitoring/lit-controller/{authUserId}?cycleId=1&startDate=2026-01-06&endDate=2026-01-16
     */
    public function monitorSingleLitController(Request $request, int $authUserId): JsonResponse
    {
        try {
            // Validate required and optional params
            $request->validate([
                'cycleId' => 'required|integer|exists:tbl_LitCycle,cycleId',
                'startDate' => 'nullable|date',
                'endDate' => 'nullable|date|after_or_equal:startDate',
            ]);

            $cycleId = $request->input('cycleId');
            $startDate = $request->input('startDate');
            $endDate = $request->input('endDate');

            // Check if user exists
            $user = UserReference::where('authUserId', $authUserId)->first();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Get cycle info
            $cycle = TblLitCycle::where('cycleId', $cycleId)
                ->whereNull('deletedAt')
                ->first();

            if (!$cycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cycle not found',
                ], 404);
            }

            // Build query for assignments
            $query = TblLitPhoneCollection::where('assignedTo', $authUserId)
                ->where('cycleId', $cycleId)
                ->whereNull('deletedAt');

            // Apply date filters if provided
            if ($startDate && $endDate) {
                $query->whereRaw(
                    'DATE("assignedAt" AT TIME ZONE \'Asia/Yangon\') BETWEEN ? AND ?',
                    [$startDate, $endDate]
                );
            }

            $assignments = $query->get();

            // Calculate metrics
            $totalAssigned = $assignments->count();
            $totalPending = $assignments->where('status', 'pending')->count();
            $totalCompleted = $assignments->where('status', 'completed')->count();

            // Get all call details for this user's assignments
            $litPhoneCollectionIds = $assignments->pluck('litPhoneCollectionId')->toArray();

            $callDetailsQuery = TblLitPhoneCollectionDetail::whereIn('litPhoneCollectionId', $litPhoneCollectionIds)
                ->whereNull('deletedAt');

            // Apply date filter on call details if provided
            if ($startDate && $endDate) {
                $callDetailsQuery->whereRaw(
                    'DATE("createdAt" AT TIME ZONE \'Asia/Yangon\') BETWEEN ? AND ?',
                    [$startDate, $endDate]
                );
            }

            $callDetails = $callDetailsQuery->get();

            // Calculate call metrics
            $totalAttempts = $callDetails->count();
            $callsByStatus = $callDetails->groupBy('callStatus')->map->count()->toArray();

            $responseData = [
                'success' => true,
                'message' => 'Litigation Controller monitoring data retrieved successfully',
                'data' => [
                    'user' => [
                        'authUserId' => $authUserId,
                        'userFullName' => $user->userFullName,
                    ],
                    'cycle' => [
                        'cycleId' => $cycle->cycleId,
                        'cycleName' => $cycle->cycleName,
                        'startDate' => $cycle->cycleDateFrom?->format('Y-m-d'),
                        'endDate' => $cycle->cycleDateTo?->format('Y-m-d'),
                        'isActive' => $cycle->cycleActive,
                    ],
                    'total' => [
                        'assigned' => $totalAssigned,
                        'pending' => $totalPending,
                        'completed' => $totalCompleted,
                    ],
                    'calls' => [
                        'totalAttempts' => $totalAttempts,
                        'byStatus' => $callsByStatus,
                    ],
                ]
            ];

            // Add dateRange to response if filters were applied
            if ($startDate && $endDate) {
                $responseData['data']['dateRange'] = [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                ];
            }

            return response()->json($responseData, 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch Litigation Controller monitoring data', [
                'authUserId' => $authUserId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve monitoring data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Monitor all Litigation Controllers performance
     * GET /api/monitoring/lit-controllers?cycleId=1&startDate=2026-01-06&endDate=2026-01-16
     */
    public function monitorAllLitControllers(Request $request): JsonResponse
    {
        try {
            // Increase limits
            ini_set('memory_limit', '512M');
            set_time_limit(300);

            Log::info('DEBUG monitorAllLitControllers START', $request->all());

            $validator = Validator::make($request->all(), [
                'cycleId' => 'required|integer|exists:tbl_LitCycle,cycleId',
                'startDate' => 'nullable|date',
                'endDate' => 'nullable|date',
            ]);

            $validator->sometimes('endDate', 'after_or_equal:startDate', function ($input) {
                return !empty($input->startDate) && !empty($input->endDate);
            });

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $cycleId = $request->input('cycleId');
            $startDate = $request->input('startDate');
            $endDate = $request->input('endDate');

            // Get cycle info
            $cycle = TblLitCycle::where('cycleId', $cycleId)
                ->whereNull('deletedAt')
                ->first();

            if (!$cycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cycle not found',
                ], 404);
            }

            // Build query - Filter NULL in SQL
            $query = TblLitPhoneCollection::where('cycleId', $cycleId)
                ->whereNull('deletedAt')
                ->whereNotNull('assignedTo');

            if ($startDate && $endDate) {
                $query->whereRaw(
                    'DATE("assignedAt" AT TIME ZONE \'Asia/Yangon\') BETWEEN ? AND ?',
                    [$startDate, $endDate]
                );
            }

            // Only select needed columns
            $query->select([
                'assignedTo',
                'status',
                'litPhoneCollectionId'
            ]);

            $assignments = $query->get();
            Log::info('Assignments fetched', ['count' => $assignments->count()]);

            // Group by Controller
            $assignmentsByController = $assignments->groupBy('assignedTo');
            Log::info('Grouped by Controller', ['groups' => $assignmentsByController->count()]);

            // Prepare Controller list
            $controllerList = [];
            $grandTotalAssigned = 0;
            $grandTotalPending = 0;
            $grandTotalCompleted = 0;

            foreach ($assignmentsByController as $assignedToId => $controllerAssignments) {
                $user = UserReference::where('authUserId', $assignedToId)->first();
                if (!$user) {
                    Log::warning('User not found', ['authUserId' => $assignedToId]);
                    continue;
                }

                $totalAssigned = $controllerAssignments->count();
                $totalPending = $controllerAssignments->where('status', 'pending')->count();
                $totalCompleted = $controllerAssignments->where('status', 'completed')->count();

                $grandTotalAssigned += $totalAssigned;
                $grandTotalPending += $totalPending;
                $grandTotalCompleted += $totalCompleted;

                $controllerList[] = [
                    'user' => [
                        'authUserId' => $user->authUserId,
                        'userFullName' => $user->userFullName,
                    ],
                    'total' => [
                        'assigned' => $totalAssigned,
                        'pending' => $totalPending,
                        'completed' => $totalCompleted,
                    ],
                ];
            }

            $responseData = [
                'success' => true,
                'message' => 'Litigation team monitoring data retrieved successfully',
                'data' => [
                    'cycle' => [
                        'cycleId' => $cycle->cycleId,
                        'cycleName' => $cycle->cycleName,
                        'startDate' => $cycle->cycleDateFrom?->format('Y-m-d'),
                        'endDate' => $cycle->cycleDateTo?->format('Y-m-d'),
                        'isActive' => $cycle->cycleActive,
                    ],
                    'controllers' => $controllerList,
                    'grandTotal' => [
                        'assigned' => $grandTotalAssigned,
                        'pending' => $grandTotalPending,
                        'completed' => $grandTotalCompleted,
                    ],
                ]
            ];

            if ($startDate && $endDate) {
                $responseData['data']['dateRange'] = [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                ];
            }

            Log::info('SUCCESS', ['controllers' => count($controllerList)]);
            return response()->json($responseData, 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch Litigation team monitoring data', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve monitoring data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
