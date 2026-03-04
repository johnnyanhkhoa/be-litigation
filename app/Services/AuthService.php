<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Models\User;

class AuthService
{
    private const BASE_URL = 'https://users-ms.vnapp.xyz';
    private const LOGIN_ENDPOINT = '/oauth/token';

    /**
     * Authenticate user with new login API
     *
     * @param string $email
     * @param string $password
     * @return array
     * @throws Exception
     */
    public function login(string $email, string $password): array
    {
        try {
            Log::info('Attempting authentication with new login API', [
                'email' => $email,
            ]);

            // Get team ID from config
            $teamId = config('services.users_ms.call_collection_team_id');

            if (!$teamId) {
                throw new Exception('Call Collection team ID not configured', 500);
            }

            Log::info('Using Call Collection team ID', [
                'team_id' => $teamId
            ]);

            // Call new login API
            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-Team-Id' => (string)$teamId,
                ])
                ->post(self::BASE_URL . '/api/login', [
                    'email' => trim($email),
                    'password' => $password,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Authentication successful', [
                    'email' => $email,
                    'user_id' => $data['current_user']['user']['user_id'] ?? null,
                    'username' => $data['current_user']['user']['username'] ?? null,
                    'roles' => $data['current_user']['roles'] ?? [],
                    'permissions' => $data['current_user']['permissions'] ?? [],
                    'expires_at' => $data['expires_at'] ?? null,
                ]);

                return $data;
            }

            // Handle authentication failure
            $errorData = $response->json();
            Log::warning('Authentication failed', [
                'email' => $email,
                'status' => $response->status(),
                'error' => $errorData
            ]);

            throw new Exception(
                $errorData['message'] ?? 'Authentication failed',
                $response->status()
            );

        } catch (Exception $e) {
            Log::error('Login API error', [
                'email' => $email,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            throw new Exception(
                'Unable to authenticate: ' . $e->getMessage(),
                $e->getCode() ?: 500
            );
        }
    }

    // /**
    //  * Authenticate user with external auth API (MOCK MODE)
    //  *
    //  * @param string $username
    //  * @param string $password
    //  * @return array
    //  * @throws Exception
    //  */
    // public function login(string $username, string $password): array
    // {
    //     Log::warning('USING MOCK LOGIN SERVICE - EXTERNAL API IS DOWN');

    //     // Giả lập logic kiểm tra đăng nhập đơn giản
    //     // Mặc dù password không dùng để xác thực, vẫn nên kiểm tra cơ bản
    //     if (empty($username) || empty($password)) {
    //         throw new Exception('Invalid credentials (MOCK)', 401);
    //     }

    //     // Giả lập dữ liệu phản hồi xác thực thành công
    //     return [
    //         'token_type' => 'Bearer',
    //         'expires_in' => 3600,
    //         // Giả lập token, dùng username để dễ dàng tìm lại thông tin ở hàm getUserInfo
    //         'access_token' => 'mocked_token::' . $username,
    //         'refresh_token' => 'mocked_refresh_token',
    //     ];
    // }

    /**
     * Get current user info using access token (API v2)
     *
     * @param string $accessToken
     * @return array
     * @throws Exception
     */
    public function getUserInfo(string $accessToken): array // Tạm thời comment lại vì Auth của Zay Yar bị lỗi
    {
        try {
            Log::info('Attempting to get current user info from v2 API', [
                'endpoint' => self::BASE_URL . '/api/v2/current-user',
                'token_preview' => substr($accessToken, 0, 20) . '...'
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get(self::BASE_URL . '/api/v2/current-user');

            Log::info('Current user API v2 response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Validate response structure for v2
                if (isset($data['status']) && $data['status'] == 1 && isset($data['data'])) {
                    return $data; // Return full response including status, data, message
                }

                throw new Exception('Invalid response format from current-user v2 endpoint');
            }

            throw new Exception('Failed to get user info - Status: ' . $response->status() . ' Body: ' . $response->body(), $response->status());

        } catch (Exception $e) {
            Log::error('Failed to get current user info from v2', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            throw $e;
        }
    }

    // /**
    //  * Get current user info using access token (MOCK MODE - Query Local DB)
    //  *
    //  * @param string $accessToken
    //  * @return array
    //  * @throws Exception
    //  */
    // public function getUserInfo(string $accessToken): array
    // {
    //     Log::warning('USING MOCK GET USER INFO SERVICE - QUERYING LOCAL DB');

    //     // 1. Trích xuất username từ mock token
    //     $parts = explode('::', $accessToken);
    //     if (count($parts) !== 2 || $parts[0] !== 'mocked_token') {
    //         throw new Exception('Invalid mock access token', 401);
    //     }
    //     $username = $parts[1];

    //     // 2. Tra cứu người dùng trong DB nội bộ bằng username (email)
    //     $localUser = User::where('email', $username)->first();

    //     if (!$localUser) {
    //         Log::error('User not found in local DB during MOCK getUserInfo', ['username' => $username]);
    //         throw new Exception('User info not found in local system (MOCK)', 404);
    //     }

    //     // 3. Giả lập phản hồi API v2
    //     return [
    //         'status' => 1,
    //         'message' => 'User info retrieved successfully (MOCK)',
    //         'data' => [
    //             // Dùng authUserId để khớp logic của AuthController
    //             'user_id' => $localUser->authUserId ?? $localUser->id,
    //             'old_user_id' => null,
    //             'username' => $localUser->username,
    //             'user_full_name' => $localUser->userFullName,
    //             'emp_no' => 'EMP' . ($localUser->authUserId ?? $localUser->id),
    //             'email' => $localUser->email,
    //             'phone_no' => '0123456789', // Giả lập
    //             'ext_no' => $localUser->extensionNo,
    //             'created_at' => $localUser->createdAt ? $localUser->createdAt->toDateTimeString() : now()->toDateTimeString(),
    //             'updated_at' => $localUser->updatedAt ? $localUser->updatedAt->toDateTimeString() : now()->toDateTimeString(),
    //         ],
    //     ];
    // }

    /**
     * Logout user from external auth API
     *
     * @param string $accessToken
     * @return array
     * @throws Exception
     */
    public function logout(string $accessToken): array
    {
        // // BẮT ĐẦU: PHẦN MOCK TẠM THỜI
        // Log::warning('USING MOCK LOGOUT SERVICE - EXTERNAL API IS DOWN');

        // // Không cần logic phức tạp cho logout trong môi trường mock
        // return [
        //     'success' => true,
        //     'message' => 'Token revoked successfully (MOCK)'
        // ];
        // // KẾT THÚC: PHẦN MOCK TẠM THỜI

        try { // Tạm thời comment lại vì Auth của Zay Yar bị lỗi
            Log::info('Attempting to logout user', [
                'endpoint' => self::BASE_URL . '/api/v1/logout',
                'token_preview' => substr($accessToken, 0, 20) . '...'
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post(self::BASE_URL . '/api/v1/logout');

            Log::info('Logout API response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Logout successful', [
                    'message' => $data['message'] ?? 'Logged out successfully'
                ]);

                return $data;
            }

            throw new Exception('Failed to logout - Status: ' . $response->status() . ' Body: ' . $response->body(), $response->status());

        } catch (Exception $e) {
            Log::error('Logout error', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            throw $e;
        }
    }

    /**
     * Check if user has permission for specific action
     *
     * @param string $accessToken
     * @param string $teamId
     * @return array
     * @throws Exception
     */
    public function checkPermission(string $accessToken, string $teamId): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Team-Id' => $teamId,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post(self::BASE_URL . '/api/v1/is-allow');

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception('Permission check failed', $response->status());

        } catch (Exception $e) {
            Log::error('Permission check error', [
                'team_id' => $teamId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Get all teams
     *
     * @param string $accessToken
     * @return array
     * @throws Exception
     */
    public function getAllTeams(string $accessToken): array
    {
        // // BẮT ĐẦU: PHẦN MOCK TẠM THỜI
        // Log::warning('USING MOCK GET ALL TEAMS SERVICE - EXTERNAL API IS DOWN');

        // // Dữ liệu giả lập, thêm các team cần thiết mà ứng dụng của bạn sử dụng
        // $mockTeams = [
        //     [
        //         'team_id' => 101,
        //         'name' => 'Collection_Team_A', // Phải khớp với teamName bạn dùng để kiểm tra
        //         'description' => 'Collection Team A',
        //         'is_active' => true,
        //     ],
        //     [
        //         'team_id' => 102,
        //         'name' => 'Admin_PMT',
        //         'description' => 'PMT Admin Team',
        //         'is_active' => true,
        //     ],
        // ];

        // return [
        //     'status' => 1,
        //     'message' => 'Teams retrieved successfully (MOCK)',
        //     'data' => [
        //         'current_page' => 1,
        //         'data' => $mockTeams,
        //         'per_page' => 20,
        //         'total' => count($mockTeams),
        //     ]
        // ];
        // // KẾT THÚC: PHẦN MOCK TẠM THỜI

        try { // Tạm thời comment lại vì Auth của Zay Yar bị lỗi
            Log::info('Fetching all teams from external API');

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get(self::BASE_URL . '/api/v1/teams');

            Log::info('Teams API response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['status']) && $data['status'] == 1 && isset($data['data']['data'])) {
                    return $data;
                }

                throw new Exception('Invalid response format from teams endpoint');
            }

            throw new Exception('Failed to fetch teams - Status: ' . $response->status(), $response->status());

        } catch (Exception $e) {
            Log::error('Failed to fetch teams', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            throw $e;
        }
    }

    /**
     * Check if user is allowed to access a team
     *
     * @param string $accessToken
     * @param int $teamId
     * @return array
     * @throws Exception
     */
    public function checkTeamPermission(string $accessToken, int $teamId): array
    {
        // // BẮT ĐẦU: PHẦN MOCK TẠM THỜI
        // Log::warning('USING MOCK CHECK TEAM PERMISSION SERVICE - EXTERNAL API IS DOWN');

        // $isAllowed = true;
        // $message = 'User is allowed to access the team (MOCK)';
        // $status = 1;

        // // Giả lập logic kiểm tra: ví dụ, chỉ cho phép team ID 101
        // if ($teamId != 101) {
        //     $isAllowed = false;
        //     $message = 'User is NOT allowed to access this team (MOCK)';
        //     $status = 0;
        // }

        // return [
        //     'status' => $status,
        //     'message' => $message,
        //     'is_allow' => $isAllowed,
        //     'data' => [
        //         'user' => [
        //             // Dữ liệu người dùng giả lập (nếu API gốc có trả về)
        //             'user_id' => 999,
        //         ],
        //         'team' => [
        //             'team_id' => $teamId
        //         ]
        //     ]
        // ];
        // // KẾT THÚC: PHẦN MOCK TẠM THỜI

        try { // Tạm thời comment lại vì Auth của Zay Yar bị lỗi
            Log::info('Checking team permission', [
                'team_id' => $teamId
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Team-Id' => (string)$teamId,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get(self::BASE_URL . '/api/v1/is-allow');

            Log::info('is-allow API response', [
                'status' => $response->status(),
                'team_id' => $teamId,
                'body' => $response->body()
            ]);

            if ($response->successful() || $response->status() === 403) {
                // Return response as-is (both success and permission denied)
                return $response->json();
            }

            throw new Exception('Failed to check permission - Status: ' . $response->status(), $response->status());

        } catch (Exception $e) {
            Log::error('Failed to check team permission', [
                'team_id' => $teamId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Get user roles and permissions by team
     *
     * @param string $accessToken
     * @param int $teamId
     * @return array
     * @throws Exception
     */
    public function getUserRolesByTeam(string $accessToken, int $teamId): array // Tạm thời comment lại vì Auth của Zay Yar bị lỗi
    {
        try {
            Log::info('Getting user roles and permissions', [
                'team_id' => $teamId
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Team-Id' => (string)$teamId,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get(self::BASE_URL . '/api/v1/current-user');

            Log::info('User roles API response', [
                'status' => $response->status(),
                'team_id' => $teamId,
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Validate response structure
                if (isset($data['status']) && $data['status'] == 1 && isset($data['data'])) {
                    return $data;
                }

                throw new Exception('Invalid response format from current-user endpoint');
            }

            throw new Exception('Failed to get user roles - Status: ' . $response->status(), $response->status());

        } catch (Exception $e) {
            Log::error('Failed to get user roles', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // /**
    //  * Get user roles and permissions for a specific team (MOCK)
    //  *
    //  * @param string $accessToken
    //  * @param int $teamId
    //  * @return array
    //  * @throws Exception
    //  */
    // public function getUserRolesByTeam(string $accessToken, int $teamId): array
    // {
    //     Log::warning('USING MOCK GET USER ROLES BY TEAM SERVICE - EXTERNAL API IS DOWN');

    //     // Giả lập vai trò và quyền hạn.
    //     $mockRoles = ['Collector', 'Supervisor'];
    //     $mockPermissions = ['view-customer', 'update-log', 'make-call'];

    //     // Có thể thay đổi dựa trên teamId
    //     if ($teamId === 102) { // Ví dụ Admin_PMT
    //         $mockRoles = ['Admin'];
    //         $mockPermissions = ['manage-pmt-guideline', 'view-all-reports'];
    //     }

    //     return [
    //         'status' => 1,
    //         'message' => 'Roles and permissions retrieved successfully (MOCK)',
    //         'data' => [
    //             'roles' => $mockRoles,
    //             'permissions' => $mockPermissions,
    //         ],
    //     ];
    // }

    /**
     * Get current user info with team context
     *
     * @param string $accessToken
     * @param int $teamId
     * @return array
     * @throws Exception
     */
    public function getCurrentUserByTeam(string $accessToken, int $teamId): array
    {
        try {
            Log::info('Getting current user info by team', [
                'team_id' => $teamId
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Team-Id' => (string)$teamId,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get(self::BASE_URL . '/api/v1/current-user');

            Log::info('Current user API response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Validate response structure
                if (isset($data['status']) && $data['status'] == 1 && isset($data['data'])) {
                    return $data;
                }

                throw new Exception('Invalid response format from current-user endpoint');
            }

            throw new Exception('Failed to get user info - Status: ' . $response->status(), $response->status());

        } catch (Exception $e) {
            Log::error('Failed to get current user by team', [
                'team_id' => $teamId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Get user info from multiple teams and merge data
     *
     * @param string $accessToken
     * @param array $teamIds
     * @return array|null
     */
    public function getUserInfoFromTeams(string $accessToken, array $teamIds): ?array
    {
        $userData = null;

        foreach ($teamIds as $teamId) {
            try {
                $response = $this->getCurrentUserByTeam($accessToken, $teamId);

                if (isset($response['data'])) {
                    $userData = $response['data'];

                    Log::info('User info retrieved from team', [
                        'team_id' => $teamId,
                        'user_id' => $userData['user']['user_id'] ?? null
                    ]);

                    // If successful, return immediately
                    return $userData;
                }
            } catch (Exception $e) {
                Log::warning('Failed to get user info from team', [
                    'team_id' => $teamId,
                    'error' => $e->getMessage()
                ]);
                // Continue to next team
                continue;
            }
        }

        return $userData;
    }

    /**
     * Sync user to local database
     *
     * @param array $externalUser
     * @return User
     */
    public function syncUserToLocal(array $externalUser): User
    {
        $authUserId = $externalUser['user_id'];

        $userData = [
            'authUserId' => $authUserId,
            'username' => $externalUser['username'],
            'userFullName' => $externalUser['user_full_name'],
            'email' => $externalUser['email'],
            'extensionNo' => $externalUser['ext_no'],
            'isActive' => $externalUser['active'] == '1',
            'lastLoginAt' => now(),
        ];

        $user = User::updateOrCreate(
            ['authUserId' => $authUserId],
            $userData
        );

        Log::info('User synced to local database', [
            'auth_user_id' => $authUserId,
            'local_user_id' => $user->id,
            'username' => $user->username,
            'action' => $user->wasRecentlyCreated ? 'created' : 'updated'
        ]);

        return $user;
    }

    /**
     * Login user and get permissions for multiple teams
     *
     * @param string $email
     * @param string $password
     * @param array $teamIds - Array of team IDs to check
     * @return array
     * @throws Exception
     */
    public function loginMultipleTeams(string $email, string $password, array $teamIds): array
    {
        $results = [];
        $lastSuccessfulAuth = null;

        foreach ($teamIds as $teamKey => $teamId) {
            try {
                Log::info('Attempting authentication for team', [
                    'email' => $email,
                    'team_key' => $teamKey,
                    'team_id' => $teamId
                ]);

                $response = Http::timeout(30)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'X-Team-Id' => (string)$teamId,
                    ])
                    ->post(self::BASE_URL . '/api/login', [
                        'email' => trim($email),
                        'password' => $password,
                    ]);

                if ($response->successful()) {
                    $data = $response->json();

                    Log::info('Authentication successful for team', [
                        'email' => $email,
                        'team_key' => $teamKey,
                        'team_id' => $teamId,
                        'roles' => $data['current_user']['roles'] ?? [],
                        'permissions' => $data['current_user']['permissions'] ?? []
                    ]);

                    $results[$teamKey] = [
                        'hasAccess' => true,
                        'user' => $data['current_user']['user'] ?? null,
                        'roles' => $data['current_user']['roles'] ?? [],
                        'permissions' => $data['current_user']['permissions'] ?? [],
                        'teams' => $data['current_user']['teams'] ?? [],
                        'auth' => [
                            'access_token' => $data['access_token'],
                            'token_type' => $data['token_type'],
                            'expires_at' => $data['expires_at']
                        ]
                    ];

                    // Keep last successful auth for token
                    $lastSuccessfulAuth = $results[$teamKey]['auth'];

                } else {
                    // Check if user doesn't belong to team
                    $errorData = $response->json();

                    if (isset($errorData['status']) && $errorData['status'] == 0) {
                        Log::info('User does not belong to team', [
                            'email' => $email,
                            'team_key' => $teamKey,
                            'team_id' => $teamId,
                            'message' => $errorData['message']
                        ]);

                        $results[$teamKey] = [
                            'hasAccess' => false,
                            'roles' => [],
                            'permissions' => [],
                            'teams' => [],
                            'message' => $errorData['message'] ?? 'User does not belong to this team.'
                        ];
                    } else {
                        // Other authentication errors
                        throw new Exception(
                            $errorData['message'] ?? 'Authentication failed',
                            $response->status()
                        );
                    }
                }

            } catch (Exception $e) {
                Log::error('Login API error for team', [
                    'email' => $email,
                    'team_key' => $teamKey,
                    'team_id' => $teamId,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode()
                ]);

                // If this is first team and failed, throw error
                if ($teamKey === array_key_first($teamIds)) {
                    throw $e;
                }

                // For subsequent teams, mark as no access
                $results[$teamKey] = [
                    'hasAccess' => false,
                    'roles' => [],
                    'permissions' => [],
                    'teams' => [],
                    'message' => 'Authentication error: ' . $e->getMessage()
                ];
            }
        }

        // If no successful auth, throw error
        if (!$lastSuccessfulAuth) {
            throw new Exception('Authentication failed for all teams', 401);
        }

        return [
            'modules' => $results,
            'auth' => $lastSuccessfulAuth // Use last successful auth token
        ];
    }

    /**
     * Get users by team ID
     *
     * @param string $accessToken
     * @param int $teamId
     * @return array
     * @throws Exception
     */
    public function getUsersByTeamId(string $accessToken, int $teamId): array
    {
        try {
            Log::info('Getting users by team ID from Auth Service', [
                'team_id' => $teamId
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'X-Team-Id' => (string)$teamId,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get(self::BASE_URL . '/api/v1/users');

            Log::info('Users API response', [
                'status' => $response->status(),
                'team_id' => $teamId,
                'body' => $response->body()  // Log để debug
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['status']) && $data['status'] == 1 && isset($data['data'])) {
                    // Extract user_ids from response
                    // Response format: data[].user.user_id
                    $userIds = collect($data['data'])
                        ->map(function ($item) {
                            return $item['user']['user_id'] ?? null;
                        })
                        ->filter()  // Remove null values
                        ->toArray();

                    Log::info('Users fetched from Auth Service', [
                        'team_id' => $teamId,
                        'count' => count($userIds),
                        'user_ids' => $userIds
                    ]);

                    return $userIds;
                }

                throw new Exception('Invalid response format from users endpoint');
            }

            throw new Exception('Failed to get users - Status: ' . $response->status());

        } catch (Exception $e) {
            Log::error('Error getting users by team ID', [
                'team_id' => $teamId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get team ID by team name
     *
     * @param string $accessToken
     * @param string $teamName
     * @return int|null
     */
    public function getTeamIdByName(string $accessToken, string $teamName): ?int
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ])->get(self::BASE_URL . '/api/v1/teams');

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['data']['data'])) {
                    $teams = collect($data['data']['data']);
                    $team = $teams->firstWhere('name', $teamName);

                    if ($team) {
                        Log::info('Team found', [
                            'team_name' => $teamName,
                            'team_id' => $team['team_id']
                        ]);
                        return $team['team_id'];
                    }
                }

                Log::warning('Team not found', ['team_name' => $teamName]);
                return null;
            }

            Log::error('Failed to fetch teams from Auth Service', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;

        } catch (Exception $e) {
            Log::error('Exception fetching team ID', [
                'team_name' => $teamName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
