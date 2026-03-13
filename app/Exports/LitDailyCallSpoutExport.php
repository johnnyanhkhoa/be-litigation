<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;


class LitDailyCallSpoutExport
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
        Log::info('Starting Litigation Daily Call Spout Excel export', [
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

        // Build and execute query directly with chunk
        $query = $this->buildQuery();

        $query->orderBy('call_date', 'desc')
            ->orderBy('call_start_time', 'desc')
            ->chunk($chunkSize, function ($calls) use ($writer, &$processedCount) {
                $rows = [];
                foreach ($calls as $call) {
                    $rowData = $this->mapRow($call);
                    $rows[] = Row::fromValues($rowData);
                    $processedCount++;
                }

                // Write rows in batch
                $writer->addRows($rows);

                Log::info('Processed Litigation daily call chunk', ['count' => $processedCount]);

                // Force garbage collection
                unset($rows, $calls);
                gc_collect_cycles();
            });

        $writer->close();

        Log::info('Litigation Daily Call Spout Excel export completed', [
            'total_records' => $processedCount,
            'file_size' => filesize($filePath)
        ]);

        return $processedCount;
    }

    protected function buildQuery()
    {
        // Subquery to get latest call detail per contract per day
        $latestCallSubquery = DB::table('tbl_LitPhoneCollectionDetail')
            ->select([
                'litPhoneCollectionId',
                DB::raw("DATE(\"createdAt\" AT TIME ZONE 'Asia/Yangon') as call_date"),
                DB::raw('MAX("litPhoneCollectionDetailId") as latest_detail_id')
            ])
            ->whereNull('deletedAt')
            ->whereRaw(
                "DATE(\"createdAt\" AT TIME ZONE 'Asia/Yangon') BETWEEN ? AND ?",
                [$this->fromDate, $this->toDate]
            )
            ->groupBy('litPhoneCollectionId', DB::raw("DATE(\"createdAt\" AT TIME ZONE 'Asia/Yangon')"));

        // Main query
        $query = DB::table('tbl_LitPhoneCollectionDetail as pcd')
            ->joinSub($latestCallSubquery, 'latest', function ($join) {
                $join->on('pcd.litPhoneCollectionId', '=', 'latest.litPhoneCollectionId')
                    ->on('pcd.litPhoneCollectionDetailId', '=', 'latest.latest_detail_id');
            })
            ->join('tbl_LitPhoneCollection as pc', 'pcd.litPhoneCollectionId', '=', 'pc.litPhoneCollectionId')
            ->leftJoin('tbl_LitCaseResult as cr', 'pcd.caseResultId', '=', 'cr.caseResultId')
            ->leftJoin('user_references as u_assigned_to', function($join) {
                $join->on(DB::raw('CAST(pc."assignedTo" AS VARCHAR)'), '=', 'u_assigned_to.id');
            })
            ->leftJoin('user_references as u_assigned_by', function($join) {
                $join->on(DB::raw('CAST(pc."assignedBy" AS VARCHAR)'), '=', 'u_assigned_by.id');
            })
            ->leftJoin('user_references as u_created_by', function($join) {
                $join->on(DB::raw('CAST(pcd."createdBy" AS VARCHAR)'), '=', 'u_created_by.id');
            })
            ->whereNull('pc.deletedAt')
            ->whereNull('pcd.deletedAt');

        // Add cycle filter if provided
        if ($this->cycleId) {
            $query->where('pc.cycleId', $this->cycleId);
        }

        $query->select([
            DB::raw("DATE(pcd.\"createdAt\" AT TIME ZONE 'Asia/Yangon') as call_date"),
            DB::raw("TO_CHAR(pcd.\"dtCallStarted\" AT TIME ZONE 'Asia/Yangon', 'HH24:MI:SS') as call_start_time"),
            DB::raw("TO_CHAR(pcd.\"dtCallEnded\" AT TIME ZONE 'Asia/Yangon', 'HH24:MI:SS') as call_end_time"),
            DB::raw("EXTRACT(EPOCH FROM (pcd.\"dtCallEnded\" - pcd.\"dtCallStarted\")) as call_duration_sec"),
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
            'pcd.callStatus',
            'cr.caseResultName',
            'pcd.remark',
            'pcd.contactType',
            'pcd.contactPhoneNumber',
            'pcd.contactName',
            'pcd.contactRelation',
            DB::raw("TO_CHAR(pcd.\"promisedPaymentDate\", 'YYYY-MM-DD') as promised_payment_date"),
            'pcd.promisedPaymentAmount',
            DB::raw("TO_CHAR(pcd.\"claimedPaymentDate\", 'YYYY-MM-DD') as claimed_payment_date"),
            'pcd.claimedPaymentAmount',
            'pcd.isUncontactable',
            'u_assigned_to.userFullName as assigned_to_name',
            'u_assigned_by.userFullName as assigned_by_name',
            DB::raw("TO_CHAR(pc.\"assignedAt\" AT TIME ZONE 'Asia/Yangon', 'YYYY-MM-DD HH24:MI:SS') as assigned_at"),
            'pc.totalAttempts',
            'u_created_by.userFullName as created_by_name',
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
        ]);

        return $query;
    }

    protected function getHeaders()
    {
        return [
            // Call Info (4)
            'Call Date', 'Call Start Time', 'Call End Time', 'Call Duration (seconds)',

            // Contract Info (8)
            'Contract No', 'Contract Date', 'Contract Type', 'Product Type',
            'Product Name', 'Product Color', 'Plate No', 'Unit Price',

            // Customer Info (6)
            'Customer Name', 'Gender', 'Age', 'Birth Date', 'Sales Area', 'Branch',

            // Payment Info (8)
            'Payment Status', 'Due Date', 'Days Overdue Gross', 'Days Overdue Net',
            'Days Since Last Payment', 'Total Overdue Amount', 'Last Payment Date', 'Cycle ID',

            // Call Details Info (12)
            'Call Status', 'Case Result', 'Remark', 'Contact Type',
            'Contact Phone Number', 'Contact Name', 'Contact Relation',
            'Promised Payment Date', 'Promised Payment Amount',
            'Claimed Payment Date', 'Claimed Payment Amount', 'Is Uncontactable',

            // Assignment Info (5)
            'Assigned To', 'Assigned By', 'Assigned At', 'Total Attempts', 'Call Created By',

            // Contact Info (4)
            'Phone No 1', 'Phone No 2', 'Phone No 3', 'Home Address',

            // Reference ID (1)
            'Litigation Phone Collection ID'
        ];
    }

    protected function mapRow($call)
    {
        return [
            // Call Info
            $call->call_date ?? '',
            $call->call_start_time ?? '',
            $call->call_end_time ?? '',
            $call->call_duration_sec ?? 0,

            // Contract Info
            $call->contractNo ?? '',
            $call->contract_date ?? '',
            $call->contractType ?? '',
            $call->contractingProductType ?? '',
            $call->productName ?? '',
            $call->productColor ?? '',
            $call->plateNo ?? '',
            $call->unitPrice ?? '',

            // Customer Info
            $call->customerFullName ?? '',
            $call->gender ?? '',
            $call->customerAge ?? '',
            $call->birth_date ?? '',
            $call->salesAreaName ?? '',
            $call->contractPlaceName ?? '',

            // Payment Info
            $call->paymentStatus ?? '',
            $call->due_date ?? '',
            $call->daysOverdueGross ?? 0,
            $call->daysOverdueNet ?? 0,
            $call->daysSinceLastPayment ?? 0,
            $call->totalOvdAmount ?? 0,
            $call->last_payment_date ?? '',
            $call->cycleId ?? '',

            // Call Details
            $call->callStatus ?? '',
            $call->caseResultName ?? '',
            $call->remark ?? '',
            $call->contactType ?? '',
            $call->contactPhoneNumber ?? '',
            $call->contactName ?? '',
            $call->contactRelation ?? '',
            $call->promised_payment_date ?? '',
            $call->promisedPaymentAmount ?? 0,
            $call->claimed_payment_date ?? '',
            $call->claimedPaymentAmount ?? 0,
            $call->isUncontactable ? 'Yes' : 'No',

            // Assignment Info
            $call->assigned_to_name ?? '',
            $call->assigned_by_name ?? '',
            $call->assigned_at ?? '',
            $call->totalAttempts ?? 0,
            $call->created_by_name ?? '',

            // Contact Info
            $call->phoneNo1 ?? '',
            $call->phoneNo2 ?? '',
            $call->phoneNo3 ?? '',
            $call->homeAddress ?? '',

            // Reference
            $call->litPhoneCollectionId ?? '',
        ];
    }
}
