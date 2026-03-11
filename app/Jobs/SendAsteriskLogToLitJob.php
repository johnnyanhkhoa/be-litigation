<?php

namespace App\Jobs;

use App\Models\TblLitAsteriskCallLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class SendAsteriskLogToLitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payload;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        try {
            Log::info('Processing Asterisk log for Litigation', [
                'payload' => $this->payload
            ]);

            // Parse payload (handle both serialized and raw JSON formats)
            $data = $this->parsePayload();

            if (!$data) {
                Log::warning('Failed to parse Asterisk payload for Litigation');
                return;
            }

            // Extract api_id or api_call_id
            $apiCallId = $data['api_id'] ?? $data['api_call_id'] ?? null;

            if (!$apiCallId) {
                Log::warning('No api_call_id in Asterisk payload', [
                    'data' => $data
                ]);
                return;
            }

            // Save to database
            $this->saveToDatabase($data, $apiCallId);

        } catch (Exception $e) {
            Log::error('Failed to process Asterisk log for Litigation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $this->payload
            ]);
        }
    }

    private function parsePayload(): ?array
    {
        try {
            // Try to unserialize (Laravel job format)
            if (is_string($this->payload)) {
                $unserialized = @unserialize($this->payload);
                if ($unserialized !== false && isset($unserialized['data'])) {
                    return $unserialized['data'];
                }
            }

            // Try raw JSON decode
            if (is_string($this->payload)) {
                $decoded = json_decode($this->payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            // Already array
            if (is_array($this->payload)) {
                return $this->payload['data'] ?? $this->payload;
            }

            return null;

        } catch (Exception $e) {
            Log::error('Error parsing Asterisk payload', [
                'error' => $e->getMessage(),
                'payload' => $this->payload
            ]);
            return null;
        }
    }

    private function saveToDatabase(array $data, $apiCallId): void
    {
        try {
            // Find existing call log
            $callLog = TblLitAsteriskCallLog::where('apiCallId', (string) $apiCallId)
                ->whereNull('deletedAt')
                ->first();

            if (!$callLog) {
                Log::warning('Litigation call log not found for api_call_id', [
                    'api_call_id' => $apiCallId
                ]);
                return;
            }

            // Parse raw_content if exists
            $rawContent = $data['raw_content'] ?? null;
            $parsedRaw = [];

            if ($rawContent && is_string($rawContent)) {
                $decoded = json_decode($rawContent, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $parsedRaw = $decoded;
                }
            }

            // Extract calldate
            $calldate = $parsedRaw['calldate'] ?? $data['calldate'] ?? null;
            $callDate = null;
            $calledAt = null;

            if ($calldate) {
                try {
                    // Extract date part (first 10 chars: YYYY-MM-DD)
                    $callDate = substr($calldate, 0, 10);

                    // Parse full datetime and append Myanmar timezone
                    $calledAt = Carbon::parse($calldate)->setTimezone('Asia/Yangon');
                } catch (Exception $e) {
                    Log::warning('Failed to parse calldate', [
                        'calldate' => $calldate,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Extract recordfile and clean it
            $recordFile = $parsedRaw['recordfile'] ?? null;
            if ($recordFile) {
                // Extract until .wav (remove SQL query garbage)
                if (preg_match('/^(.*?\.wav)/', $recordFile, $matches)) {
                    $recordFile = $matches[1];
                }
            }

            // Update call log
            $callLog->update([
                'callDate' => $callDate,
                'calledAt' => $calledAt,
                'handleTimeSec' => $data['handle_time_sec'] ?? $data['handle'] ?? null,
                'talkTimeSec' => $data['talk_time_sec'] ?? $data['talk'] ?? null,
                'callStatus' => $data['status'] ?? null,
                'recordFile' => $recordFile,
                'asteriskCallId' => $parsedRaw['callid'] ?? null,
                'outboundCnum' => $parsedRaw['outbound_cnum'] ?? null,
                'rawContent' => $rawContent,
                'company' => $data['company'] ?? 'r2o',
                'updatedAt' => now(),
            ]);

            Log::info('Litigation call log updated from Asterisk', [
                'call_log_id' => $callLog->id,
                'api_call_id' => $apiCallId,
                'call_status' => $callLog->callStatus
            ]);

        } catch (Exception $e) {
            Log::error('Failed to save Asterisk log to Litigation database', [
                'api_call_id' => $apiCallId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
