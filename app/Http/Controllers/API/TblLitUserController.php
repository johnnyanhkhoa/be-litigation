<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TblLitControllerLto;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class TblLitUserController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function getEligibleUsers(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'cycleId' => 'nullable|integer|exists:tbl_LitCycle,cycleId',
                'isActive' => 'nullable|in:true,false,1,0',
            ]);

            $cycleId = $request->input('cycleId');
            $isActiveParam = $request->input('isActive');
            $isActive = $isActiveParam === null ? true : filter_var($isActiveParam, FILTER_VALIDATE_BOOLEAN);

            // Get access token from Authorization header
            $authHeader = $request->header('Authorization');

            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access token is required',
                    'error' => 'Missing or invalid Authorization header'
                ], 401);
            }

            $accessToken = substr($authHeader, 7);

            Log::info('Getting eligible users for Litigation', [
                'cycle_id' => $cycleId,
                'is_active' => $isActive
            ]);

            // Get all users by active status from user_references
            $users = \App\Models\UserReference::where('isActive', $isActive)
                ->orderBy('userFullName', 'asc')
                ->get();

            // Get users from Auth Service (Litigation team_id=3)
            try {
                $authServiceUserIds = $this->authService->getUsersByTeamId($accessToken, 3);

                // Filter users: only keep users who are in Auth Service team
                $users = $users->filter(function ($user) use ($authServiceUserIds) {
                    return in_array($user->authUserId, $authServiceUserIds);
                })->values();

                Log::info('Litigation users filtered by Auth Service', [
                    'total_before' => \App\Models\UserReference::where('isActive', $isActive)->count(),
                    'total_after' => $users->count(),
                    'auth_service_count' => count($authServiceUserIds)
                ]);
            } catch (Exception $e) {
                Log::error('Failed to fetch Litigation users from Auth Service', [
                    'error' => $e->getMessage()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to validate users with Auth Service',
                    'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
                ], 500);
            }

            // If cycleId provided, check who's already assigned
            $assignedUserIds = [];
            if ($cycleId) {
                $assignedAsController = \App\Models\TblLitControllerLto::notDeleted()
                    ->forCycle($cycleId)
                    ->pluck('controllerId')
                    ->toArray();

                $assignedAsLto = \App\Models\TblLitControllerLto::notDeleted()
                    ->forCycle($cycleId)
                    ->pluck('ltoId')
                    ->toArray();

                $assignedUserIds = array_unique(array_merge($assignedAsController, $assignedAsLto));
            }

            // Map users with assignment status
            $result = $users->map(function ($user) use ($assignedUserIds, $cycleId) {
                return [
                    'authUserId' => $user->authUserId,
                    'username' => $user->username,
                    'userFullName' => $user->userFullName,
                    'email' => $user->email,
                    'extensionNo' => $user->extensionNo,
                    'isActive' => $user->isActive,
                    'level' => $user->level,
                    'inAssignment' => $cycleId ? in_array($user->authUserId, $assignedUserIds) : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Eligible users retrieved successfully',
                'data' => [
                    'users' => $result,
                    'total' => $result->count(),
                    'summary' => [
                        'inAssignment' => $cycleId ? count($assignedUserIds) : null,
                        'notInAssignment' => $cycleId ? ($result->count() - count($assignedUserIds)) : null,
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get eligible users for Litigation', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get eligible users',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
