<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ExternalApiService
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = env('MAXIMUS_API_URL', 'https://maximus.vnapp.xyz/api/v1/cc');
        $this->apiKey = env('MAXIMUS_API_KEY', 't03JN3y8L12gzVbuLuorjwBAHgVAkkY6QOvJkP6m');
    }

    /**
     * Generic GET request to Maximus API
     *
     * @param string $endpoint (relative path)
     * @return array
     */
    public function get(string $endpoint): array
    {
        try {
            $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

            Log::info('Calling Maximus API (GET)', [
                'url' => $url
            ]);

            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::error('Maximus API returned error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'url' => $url
                ]);

                return [
                    'status' => 0,
                    'message' => 'External API error',
                    'data' => null,
                    'error' => $response->body()
                ];
            }

            $data = $response->json();

            Log::info('Maximus API call successful', [
                'url' => $url,
                'status' => $data['status'] ?? 'unknown'
            ]);

            return $data;

        } catch (Exception $e) {
            Log::error('Exception calling Maximus API', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 0,
                'message' => 'Failed to call external API',
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fetch contract details from Maximus API
     *
     * @param int $contractId
     * @return array
     */
    public function fetchContractDetails(int $contractId): array
    {
        Log::info('Fetching contract details from Maximus API', [
            'contract_id' => $contractId
        ]);

        $endpoint = "phone-collection/contracts/{$contractId}";
        return $this->get($endpoint);
    }
}
