<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TblLitExportToEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class LitExportEmailController extends Controller
{
    /**
     * GET /api/lit-export-emails?reportType=lit_daily_call_report
     * Get email list for a report type
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'reportType' => 'required|string|max:100'
            ]);

            $reportType = $request->input('reportType');

            $emails = TblLitExportToEmail::notDeleted()
                ->active()
                ->forReportType($reportType)
                ->orderBy('createdAt', 'asc')
                ->pluck('email')
                ->toArray();

            Log::info('Litigation export emails retrieved', [
                'report_type' => $reportType,
                'count' => count($emails)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Export emails retrieved successfully',
                'data' => [
                    'reportType' => $reportType,
                    'emails' => $emails,
                    'total' => count($emails)
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get litigation export emails', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get export emails',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * POST /api/lit-export-emails
     * Sync email list for a report type
     *
     * Body: {
     *   "reportType": "lit_daily_call_report",
     *   "emails": ["email1@gmail.com", "email2@gmail.com"],
     *   "userId": 77
     * }
     */
    public function sync(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reportType' => 'required|string|max:100',
                'emails' => 'required|array|min:1',
                'emails.*' => 'required|email|max:255',
                'userId' => 'required|integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $reportType = $request->reportType;
            $newEmails = array_unique($request->emails); // Remove duplicates
            $userId = $request->userId;

            DB::beginTransaction();

            // Get ALL records (including soft deleted) for this report type
            $allRecords = TblLitExportToEmail::forReportType($reportType)->get();

            // Get only active (not deleted) records
            $existingRecords = $allRecords->whereNull('deletedAt');

            $existingEmails = $existingRecords->pluck('email')->toArray();

            // Emails to add (in newEmails but not in existingEmails)
            $emailsToAdd = array_diff($newEmails, $existingEmails);

            // Emails to keep active (in both newEmails and existingEmails)
            $emailsToKeep = array_intersect($newEmails, $existingEmails);

            // Emails to deactivate (in existingEmails but not in newEmails)
            $emailsToDeactivate = array_diff($existingEmails, $newEmails);

            Log::info('Syncing litigation export emails', [
                'report_type' => $reportType,
                'new_emails' => $newEmails,
                'existing_emails' => $existingEmails,
                'to_add' => $emailsToAdd,
                'to_keep' => $emailsToKeep,
                'to_deactivate' => $emailsToDeactivate
            ]);

            // Add new emails OR restore soft deleted ones
            foreach ($emailsToAdd as $email) {
                // Check if email was soft deleted before
                $deletedRecord = $allRecords->where('email', $email)
                    ->whereNotNull('deletedAt')
                    ->first();

                if ($deletedRecord) {
                    // Restore soft deleted record
                    $deletedRecord->update([
                        'isActive' => true,
                        'deletedAt' => null,
                        'deletedBy' => null,
                        'deletedReason' => null,
                        'updatedAt' => now(),
                        'updatedBy' => $userId
                    ]);

                    Log::info('Restored soft deleted litigation email', [
                        'email' => $email,
                        'report_type' => $reportType
                    ]);
                } else {
                    // Create new record
                    TblLitExportToEmail::create([
                        'reportType' => $reportType,
                        'email' => $email,
                        'isActive' => true,
                        'createdAt' => now(),
                        'createdBy' => $userId
                    ]);

                    Log::info('Created new litigation email', [
                        'email' => $email,
                        'report_type' => $reportType
                    ]);
                }
            }

            // Reactivate emails if they were deactivated before
            foreach ($emailsToKeep as $email) {
                $record = $existingRecords->where('email', $email)->first();
                if ($record && !$record->isActive) {
                    $record->update([
                        'isActive' => true,
                        'updatedAt' => now(),
                        'updatedBy' => $userId
                    ]);
                }
            }

            // Soft delete emails not in new list
            foreach ($emailsToDeactivate as $email) {
                $record = $existingRecords->where('email', $email)->first();
                if ($record) {
                    $record->update([
                        'deletedAt' => now(),
                        'deletedBy' => $userId,
                        'deletedReason' => 'Removed from email list by user'
                    ]);
                }
            }

            DB::commit();

            // Get final active emails
            $finalEmails = TblLitExportToEmail::notDeleted()
                ->active()
                ->forReportType($reportType)
                ->pluck('email')
                ->toArray();

            Log::info('Litigation export emails synced', [
                'report_type' => $reportType,
                'added' => count($emailsToAdd),
                'deactivated' => count($emailsToDeactivate),
                'final_count' => count($finalEmails)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Export emails synced successfully',
                'data' => [
                    'reportType' => $reportType,
                    'emails' => $finalEmails,
                    'summary' => [
                        'added' => count($emailsToAdd),
                        'kept' => count($emailsToKeep),
                        'removed' => count($emailsToDeactivate),
                        'total' => count($finalEmails)
                    ]
                ]
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to sync litigation export emails', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync export emails',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * DELETE /api/lit-export-emails
     * Remove a specific email from report type
     *
     * Body: {
     *   "reportType": "lit_daily_call_report",
     *   "email": "email2@gmail.com",
     *   "userId": 77,
     *   "deletedReason": "Optional"
     * }
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reportType' => 'required|string|max:100',
                'email' => 'required|email|max:255',
                'userId' => 'required|integer',
                'deletedReason' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $reportType = $request->reportType;
            $email = $request->email;
            $userId = $request->userId;
            $deletedReason = $request->deletedReason ?? 'Removed by user';

            $record = TblLitExportToEmail::notDeleted()
                ->forReportType($reportType)
                ->where('email', $email)
                ->first();

            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email not found in export list'
                ], 404);
            }

            DB::beginTransaction();

            $record->update([
                'deletedAt' => now(),
                'deletedBy' => $userId,
                'deletedReason' => $deletedReason
            ]);

            DB::commit();

            Log::info('Litigation export email removed', [
                'report_type' => $reportType,
                'email' => $email,
                'deleted_by' => $userId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email removed from export list successfully'
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to remove litigation export email', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove email',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
