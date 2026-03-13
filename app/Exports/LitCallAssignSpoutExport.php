<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;

class LitCallAssignSpoutExport
{
    protected $fromDate;
    protected $toDate;
    protected $cycleId;

    public function __construct($fromDate, $toDate, $cycleId = null)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->cycleId = $cycleId;
    }

    public function export($filePath)
    {
        Log::info('Starting Litigation Call Assign Spout Excel export', [
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'cycle_id' => $this->cycleId,
            'file_path' => $filePath
        ]);

        // Create writer
        $writer = new Writer();
        $writer->openToFile($filePath);

        // Write headers
        $headerRow = Row::fromValues($this->getHeaders());
        $writer->addRow($headerRow);

        // Write data in chunks
        $chunkSize = 500;
        $processedCount = 0;

        // Build and execute query
        $query = $this->buildQuery();

        $query->orderBy('assign_date', 'desc')
            ->chunk($chunkSize, function ($assignments) use ($writer, &$processedCount) {
                $rows = [];
                foreach ($assignments as $assignment) {
                    $rowData = $this->mapRow($assignment);
                    $rows[] = Row::fromValues($rowData);
                    $processedCount++;
                }

                // Write rows in batch
                $writer->addRows($rows);

                Log::info('Processed Litigation call assign chunk', ['count' => $processedCount]);

                // Force garbage collection
                unset($rows, $assignments);
                gc_collect_cycles();
            });

        $writer->close();

        Log::info('Litigation Call Assign Spout Excel export completed', [
            'total_records' => $processedCount,
            'file_size' => filesize($filePath)
        ]);

        return $processedCount;
    }

    protected function buildQuery()
    {
        $query = DB::table('tbl_LitPhoneCollection as pc')
            ->leftJoin('user_references as u_assigned_to', function($join) {
                $join->on(DB::raw('CAST(pc."assignedTo" AS VARCHAR)'), '=', 'u_assigned_to.id');
            })
            ->leftJoin('user_references as u_assigned_by', function($join) {
                $join->on(DB::raw('CAST(pc."assignedBy" AS VARCHAR)'), '=', 'u_assigned_by.id');
            })
            ->leftJoin('user_references as u_completed_by', function($join) {
                $join->on(DB::raw('CAST(pc."completedBy" AS VARCHAR)'), '=', 'u_completed_by.id');
            })
            ->whereNull('pc.deletedAt')
            ->whereNotNull('pc.assignedTo')
            ->whereRaw(
                "DATE(pc.\"assignedAt\" AT TIME ZONE 'Asia/Yangon') BETWEEN ? AND ?",
                [$this->fromDate, $this->toDate]
            );

        // Add cycle filter if provided
        if ($this->cycleId) {
            $query->where('pc.cycleId', $this->cycleId);
        }

        $query->select([
            DB::raw("DATE(pc.\"assignedAt\" AT TIME ZONE 'Asia/Yangon') as assign_date"),
            DB::raw("TO_CHAR(pc.\"assignedAt\" AT TIME ZONE 'Asia/Yangon', 'YYYY-MM-DD HH24:MI:SS') as assigned_at"),
            'pc.contractNo',
            DB::raw("TO_CHAR(pc.\"contractDate\", 'YYYY-MM-DD') as contract_date"),
            'pc.contractType',
            'pc.contractingProductType',
            'pc.customerFullName',
            'pc.gender',
            'pc.customerAge',
            DB::raw("TO_CHAR(pc.\"birthDate\", 'YYYY-MM-DD') as birth_date"),
            'pc.paymentStatus',
            DB::raw("TO_CHAR(pc.\"dueDate\", 'YYYY-MM-DD') as due_date"),
            'pc.daysOverdueGross',
            'pc.daysOverdueNet',
            'pc.daysSinceLastPayment',
            'pc.totalOvdAmount',
            'pc.status',
            'pc.totalAttempts',
            DB::raw("TO_CHAR(pc.\"lastAttemptAt\" AT TIME ZONE 'Asia/Yangon', 'YYYY-MM-DD HH24:MI:SS') as last_attempt_at"),
            DB::raw("TO_CHAR(pc.\"completedAt\" AT TIME ZONE 'Asia/Yangon', 'YYYY-MM-DD HH24:MI:SS') as completed_at"),
            'u_assigned_to.userFullName as assigned_to_name',
            'u_assigned_by.userFullName as assigned_by_name',
            'u_completed_by.userFullName as completed_by_name',
            'pc.salesAreaName',
            'pc.contractPlaceName',
            'pc.productName',
            'pc.productColor',
            'pc.plateNo',
            'pc.unitPrice',
            'pc.phoneNo1',
            'pc.phoneNo2',
            'pc.phoneNo3',
            'pc.homeAddress',
            DB::raw("TO_CHAR(pc.\"lastPaymentDate\", 'YYYY-MM-DD') as last_payment_date"),
            'pc.cycleId',
            'pc.litPhoneCollectionId',
            'pc.contractId',
        ]);

        return $query;
    }

    protected function getHeaders()
    {
        return [
            // Assignment Info (3)
            'Assign Date', 'Assigned At', 'Assigned To',

            // Contract Info (8)
            'Contract No', 'Contract Date', 'Contract Type', 'Product Type',
            'Product Name', 'Product Color', 'Plate No', 'Unit Price',

            // Customer Info (6)
            'Customer Name', 'Gender', 'Age', 'Birth Date', 'Sales Area', 'Branch',

            // Payment Info (8)
            'Payment Status', 'Due Date', 'Days Overdue Gross', 'Days Overdue Net',
            'Days Since Last Payment', 'Total Overdue Amount', 'Last Payment Date', 'Cycle ID',

            // Status & Progress (6)
            'Status', 'Total Attempts', 'Last Attempt At', 'Completed At',
            'Assigned By', 'Completed By',

            // Contact Info (4)
            'Phone No 1', 'Phone No 2', 'Phone No 3', 'Home Address',

            // Reference IDs (2)
            'Litigation Phone Collection ID', 'Contract ID'
        ];
    }

    protected function mapRow($assignment)
    {
        return [
            // Assignment Info
            $assignment->assign_date ?? '',
            $assignment->assigned_at ?? '',
            $assignment->assigned_to_name ?? '',

            // Contract Info
            $assignment->contractNo ?? '',
            $assignment->contract_date ?? '',
            $assignment->contractType ?? '',
            $assignment->contractingProductType ?? '',
            $assignment->productName ?? '',
            $assignment->productColor ?? '',
            $assignment->plateNo ?? '',
            $assignment->unitPrice ?? '',

            // Customer Info
            $assignment->customerFullName ?? '',
            $assignment->gender ?? '',
            $assignment->customerAge ?? '',
            $assignment->birth_date ?? '',
            $assignment->salesAreaName ?? '',
            $assignment->contractPlaceName ?? '',

            // Payment Info
            $assignment->paymentStatus ?? '',
            $assignment->due_date ?? '',
            $assignment->daysOverdueGross ?? 0,
            $assignment->daysOverdueNet ?? 0,
            $assignment->daysSinceLastPayment ?? 0,
            $assignment->totalOvdAmount ?? 0,
            $assignment->last_payment_date ?? '',
            $assignment->cycleId ?? '',

            // Status & Progress
            $assignment->status ?? '',
            $assignment->totalAttempts ?? 0,
            $assignment->last_attempt_at ?? '',
            $assignment->completed_at ?? '',
            $assignment->assigned_by_name ?? '',
            $assignment->completed_by_name ?? '',

            // Contact Info
            $assignment->phoneNo1 ?? '',
            $assignment->phoneNo2 ?? '',
            $assignment->phoneNo3 ?? '',
            $assignment->homeAddress ?? '',

            // Reference
            $assignment->litPhoneCollectionId ?? '',
            $assignment->contractId ?? '',
        ];
    }
}
