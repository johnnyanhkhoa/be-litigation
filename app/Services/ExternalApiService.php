<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ExternalApiService
{
    private const BASE_URL = 'https://maximus.vnapp.xyz/api/v1/cc';
    // private const BASE_URL = 'https://maximus-staging.vnapp.xyz/api/v1/cc';
    private const API_KEY = 't03JN3y8L12gzVbuLuorjwBAHgVAkkY6QOvJkP6m';

    /**
     * Fetch contract details from Maximus API
     *
     * @param int $contractId
     * @return array
     */
    public function fetchContractDetails(int $contractId): array
    {
        try {
            Log::info('Fetching contract details from Maximus API', [
                'contract_id' => $contractId,
                'api_url' => self::BASE_URL
            ]);

            $url = self::BASE_URL . "/phone-collection/contracts/{$contractId}";

            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'x-api-key' => self::API_KEY,
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::error('Maximus API request failed', [
                    'contract_id' => $contractId,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'status' => 0,
                    'message' => 'Failed to fetch contract details from external API',
                    'data' => null
                ];
            }

            $data = $response->json();

            Log::info('Contract details fetched successfully from Maximus', [
                'contract_id' => $contractId,
                'status' => $data['status'] ?? 'unknown'
            ]);

            return $data;

        } catch (Exception $e) {
            Log::error('Exception while fetching contract details', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 0,
                'message' => 'Exception occurred while fetching contract details',
                'error' => $e->getMessage(),
                'data' => null
            ];
        }
    }
}
