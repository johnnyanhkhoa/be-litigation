<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ExportLitCallAssignReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes
    public $tries = 1;

    protected $fromDate;
    protected $toDate;
    protected $cycleId;
    protected $emails;
    protected $requestedBy;

    public function __construct($fromDate, $toDate, $cycleId, $emails, $requestedBy = null)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->cycleId = $cycleId;
        $this->emails = $emails;
        $this->requestedBy = $requestedBy;
    }

    public function handle(): void
    {
        try {
            Log::info('Starting Litigation Call Assign Report export job', [
                'from_date' => $this->fromDate,
                'to_date' => $this->toDate,
                'cycle_id' => $this->cycleId,
                'emails' => $this->emails
            ]);

            // Generate filename
            $timestamp = now()->format('YmdHis');
            $cycleText = $this->cycleId ? "_cycle_{$this->cycleId}" : "";
            $filename = "litigation_call_assign_report_{$this->fromDate}_to_{$this->toDate}{$cycleText}_{$timestamp}.xlsx";
            $storagePath = "exports/{$filename}";

            Log::info('Generating Litigation Call Assign Excel file...', ['filename' => $filename]);

            $fullPath = storage_path("app/public/{$storagePath}");

            // Ensure directory exists
            if (!file_exists(dirname($fullPath))) {
                mkdir(dirname($fullPath), 0755, true);
            }

            // Generate Excel with streaming
            $streamExport = new \App\Exports\LitCallAssignSpoutExport(
                $this->fromDate,
                $this->toDate,
                $this->cycleId
            );
            $recordCount = $streamExport->export($fullPath);

            Log::info('Litigation Call Assign Excel generated with streaming', ['records' => $recordCount]);

            // Verify file exists
            if (!file_exists($fullPath)) {
                throw new \Exception("File was not saved to storage: {$fullPath}");
            }

            $fileSize = filesize($fullPath);

            Log::info('Litigation Call Assign Excel file saved successfully', [
                'path' => $storagePath,
                'size_bytes' => $fileSize,
                'size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);

            // Generate download URL
            $baseUrl = str_replace('https://', 'http://', config('app.url'));
            $downloadUrl = $baseUrl . '/storage/' . $storagePath;
            $expiryDate = now()->addDays(7)->format('Y-m-d H:i:s');

            // Send email with download link
            Log::info('Sending Litigation call assign export emails...', ['recipients' => $this->emails]);

            foreach ($this->emails as $email) {
                try {
                    $emailBody = "Dear Team,\n\n";
                    $emailBody .= "Your requested Litigation Call Assign Report is ready for download.\n\n";
                    $emailBody .= "Report Details:\n";
                    $emailBody .= "- Date Range: {$this->fromDate} to {$this->toDate}\n";
                    if ($this->cycleId) {
                        $emailBody .= "- Cycle ID: {$this->cycleId}\n";
                    }
                    $emailBody .= "- Total Records: " . number_format($recordCount) . "\n";
                    $emailBody .= "- File Size: " . round($fileSize / 1024 / 1024, 2) . " MB\n";
                    $emailBody .= "- Generated At: " . now()->format('Y-m-d H:i:s') . "\n";
                    $emailBody .= "- Link Expires: {$expiryDate} (7 days)\n\n";
                    $emailBody .= "Download Link:\n";
                    $emailBody .= "{$downloadUrl}\n\n";
                    $emailBody .= "Note: This link will be available for 7 days.\n\n";
                    $emailBody .= "Best regards,\n";
                    $emailBody .= "Litigation System";

                    Mail::raw($emailBody, function ($message) use ($email) {
                        $message->to($email)
                            ->subject("Litigation Call Assign Report - {$this->fromDate} to {$this->toDate} - Ready for Download");
                    });

                    Log::info('Litigation call assign export email sent successfully', ['email' => $email]);
                } catch (\Exception $emailError) {
                    Log::error('Failed to send Litigation call assign export email', [
                        'email' => $email,
                        'error' => $emailError->getMessage()
                    ]);
                }
            }

            Log::info('Litigation call assign export job completed successfully', [
                'download_url' => $downloadUrl,
                'record_count' => $recordCount
            ]);

        } catch (\Exception $e) {
            Log::error('Litigation call assign export job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'from_date' => $this->fromDate,
                'to_date' => $this->toDate,
                'cycle_id' => $this->cycleId
            ]);

            // Send failure notification
            foreach ($this->emails as $email) {
                try {
                    $emailBody = "Dear Team,\n\n";
                    $emailBody .= "Unfortunately, your Litigation Call Assign Report export has failed.\n\n";
                    $emailBody .= "Report Details:\n";
                    $emailBody .= "- Date Range: {$this->fromDate} to {$this->toDate}\n";
                    if ($this->cycleId) {
                        $emailBody .= "- Cycle ID: {$this->cycleId}\n";
                    }
                    $emailBody .= "- Error: {$e->getMessage()}\n\n";
                    $emailBody .= "Please contact the system administrator or try again later.\n\n";
                    $emailBody .= "Best regards,\n";
                    $emailBody .= "Litigation System";

                    Mail::raw($emailBody, function ($message) use ($email) {
                        $message->to($email)
                            ->subject("Litigation Call Assign Report Export Failed");
                    });
                } catch (\Exception $mailError) {
                    Log::error('Failed to send failure notification', [
                        'email' => $email,
                        'error' => $mailError->getMessage()
                    ]);
                }
            }

            throw $e;
        }
    }
}
