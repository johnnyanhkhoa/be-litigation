<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class AsteriskService
{
    protected $asteriskUrl;
    protected $asteriskToken;

    public function __construct()
    {
        $this->asteriskUrl = env('ASTERISK_MS_URL', 'https://asterisk-ms.vnapp.xyz');
        $this->asteriskToken = env('ASTERISK_MS_TOKEN', '');
    }

    /**
     * Initiate a call through Asterisk Microservice
     *
     * @param string $phoneExtension
     * @param string $phoneNo
     * @param string $moduleName
     * @param string $caseId
     * @param string $username
     * @param int $userId
     * @param string $company
     * @return array
     */
    public function initiateCall(
        string $phoneExtension,
        string $phoneNo,
        string $moduleName,
        string $caseId,
        string $username,
        int $userId,
        string $company = 'r2o'
    ): array {
        try {
            $url = "{$this->asteriskUrl}/api/voice-call";

            $payload = [
                'phoneExtension' => $phoneExtension,
                'phoneNo' => $phoneNo,
                'moduleName' => $moduleName,
                'caseId' => $caseId,
                'username' => $username,
                'userId' => $userId,
                'company' => $company,
            ];

            Log::info('Calling Asterisk Microservice', [
                'url' => $url,
                'payload' => $payload
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->asteriskToken,
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Asterisk MS returned error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'status' => '0',
                    'message' => 'Failed to initiate call',
                    'error' => $response->body()
                ];
            }

            $data = $response->json();

            Log::info('Asterisk MS response', [
                'data' => $data
            ]);

            return $data;

        } catch (Exception $e) {
            Log::error('Exception calling Asterisk MS', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => '0',
                'message' => 'Exception occurred while calling Asterisk',
                'error' => $e->getMessage()
            ];
        }
    }
}
