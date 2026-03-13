<?php

namespace App\Services;

use App\Models\TblLitPhoneCollection;
use App\Models\TblLitPhoneCollectionDetail;
use App\Models\TblLitCaseResult;
use App\Models\TblLitCycle;
use App\Models\UserReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LitAnalyticsService
{
    /**
     * Get Litigation analytics summary for dashboard
     *
     * @param string $from Date in Y-m-d format
     * @param string $to Date in Y-m-d format
     * @return array
     */
    public function getAnalyticsSummary(string $from, string $to): array
    {
        Log::info('Getting Litigation analytics summary', [
            'from' => $from,
            'to' => $to
        ]);

        // Convert to datetime range
        $fromDateTime = Carbon::parse($from)->startOfDay();
        $toDateTime = Carbon::parse($to)->endOfDay();

        // Get active cycle info
        $activeCycle = TblLitCycle::where('cycleActive', true)
            ->whereNull('deletedAt')
            ->first();

        return [
            'cycle' => $activeCycle ? [
                'cycleId' => $activeCycle->cycleId,
                'cycleName' => $activeCycle->cycleName,
                'startDate' => $activeCycle->cycleDateFrom?->format('Y-m-d'),
                'endDate' => $activeCycle->cycleDateTo?->format('Y-m-d'),
            ] : null,
            'contactRate' => $this->getContactRate($fromDateTime, $toDateTime),
            'callResults' => $this->getCallResults($fromDateTime, $toDateTime),
            'agentStats' => $this->getAgentStats($fromDateTime, $toDateTime),
            'collectionTrend' => $this->getCollectionTrend($fromDateTime, $toDateTime),
            'daysOverdueDistribution' => $this->getDaysOverdueDistribution($fromDateTime, $toDateTime),
            'rescheduleStats' => $this->getRescheduleStats($fromDateTime, $toDateTime),
        ];
    }

    /**
     * Get contact rate statistics
     *
     * @param Carbon $from
     * @param Carbon $to
     * @return array
     */
    private function getContactRate($from, $to): array
    {
        // Get reached and not reached from phone collection details
        $contactStats = DB::table('tbl_LitPhoneCollectionDetail')
            ->whereNull('deletedAt')
            ->whereBetween(DB::raw('"createdAt"'), [$from, $to])
            ->selectRaw('
                COUNT(CASE WHEN "callStatus" = \'reached\' THEN 1 END) as reached,
                COUNT(CASE WHEN "callStatus" IN (\'ring\', \'busy\', \'cancelled\', \'power_off\', \'wrong_number\', \'no_contact\') THEN 1 END) as "notReached"
            ')
            ->first();

        // Get uncalled (assigned but never attempted)
        $uncalled = DB::table('tbl_LitPhoneCollection')
            ->whereNull('deletedAt')
            ->whereBetween(DB::raw('"assignedAt"'), [$from, $to])
            ->where(DB::raw('"totalAttempts"'), 0)
            ->count();

        return [
            'reached' => (int) ($contactStats->reached ?? 0),
            'notReached' => (int) ($contactStats->notReached ?? 0),
            'uncalled' => $uncalled,
        ];
    }

    /**
     * Get call results breakdown
     *
     * @param Carbon $from
     * @param Carbon $to
     * @return array
     */
    private function getCallResults($from, $to): array
    {
        $results = DB::table('tbl_LitPhoneCollectionDetail')
            ->join(
                'tbl_LitCaseResult',
                'tbl_LitPhoneCollectionDetail.caseResultId',
                '=',
                'tbl_LitCaseResult.caseResultId'
            )
            ->whereNull('tbl_LitPhoneCollectionDetail.deletedAt')
            ->whereBetween(DB::raw('"tbl_LitPhoneCollectionDetail"."createdAt"'), [$from, $to])
            ->whereNotNull(DB::raw('"tbl_LitPhoneCollectionDetail"."caseResultId"'))
            ->groupBy(DB::raw('"tbl_LitCaseResult"."caseResultName"'))
            ->selectRaw('"tbl_LitCaseResult"."caseResultName" as name, COUNT(*) as count')
            ->orderByDesc('count')
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->name,
                    'count' => (int) $item->count
                ];
            });

        return $results->toArray();
    }

    /**
     * Get agent performance statistics (Controllers in Litigation)
     *
     * @param Carbon $from
     * @param Carbon $to
     * @return array
     */
    private function getAgentStats($from, $to): array
    {
        $stats = DB::table('tbl_LitPhoneCollectionDetail')
            ->join(
                'tbl_LitPhoneCollection',
                'tbl_LitPhoneCollectionDetail.litPhoneCollectionId',
                '=',
                'tbl_LitPhoneCollection.litPhoneCollectionId'
            )
            ->join(
                'user_references',
                DB::raw('CAST("tbl_LitPhoneCollection"."assignedTo" AS VARCHAR)'),
                '=',
                'user_references.id'
            )
            ->whereNull('tbl_LitPhoneCollectionDetail.deletedAt')
            ->whereNull('tbl_LitPhoneCollection.deletedAt')
            ->whereBetween(DB::raw('"tbl_LitPhoneCollectionDetail"."createdAt"'), [$from, $to])
            ->groupBy(DB::raw('user_references."authUserId"'), DB::raw('user_references."userFullName"'))
            ->selectRaw('
                user_references."userFullName" as "agentName",
                user_references."authUserId" as "agentId",
                COUNT(DISTINCT "tbl_LitPhoneCollectionDetail"."litPhoneCollectionDetailId") as "totalCalls",
                SUM(CASE WHEN "tbl_LitPhoneCollectionDetail"."callStatus" = \'reached\' THEN 1 ELSE 0 END) as "reachedCalls",
                COUNT(DISTINCT "tbl_LitPhoneCollection"."litPhoneCollectionId") as "totalCases"
            ')
            ->orderByDesc('totalCalls')
            ->get()
            ->map(function ($item) {
                return [
                    'agentId' => (int) $item->agentId,
                    'agentName' => $item->agentName,
                    'totalCalls' => (int) $item->totalCalls,
                    'reachedCalls' => (int) $item->reachedCalls,
                    'totalCases' => (int) $item->totalCases,
                ];
            });

        return $stats->toArray();
    }

    /**
     * Get collection trend over time
     *
     * @param Carbon $from
     * @param Carbon $to
     * @return array
     */
    private function getCollectionTrend($from, $to): array
    {
        $trend = DB::table('tbl_LitPhoneCollection')
            ->whereNull('deletedAt')
            ->whereBetween(DB::raw('"assignedAt"'), [$from, $to])
            ->groupBy(DB::raw('DATE("assignedAt")'))
            ->selectRaw('
                DATE("assignedAt") as date,
                COUNT(*) as "totalAssigned",
                COUNT(CASE WHEN status = \'pending\' THEN 1 END) as pending,
                COUNT(CASE WHEN status = \'completed\' THEN 1 END) as completed
            ')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'totalAssigned' => (int) $item->totalAssigned,
                    'pending' => (int) $item->pending,
                    'completed' => (int) $item->completed,
                ];
            });

        return $trend->toArray();
    }

    /**
     * Get days overdue distribution
     *
     * @param Carbon $from
     * @param Carbon $to
     * @return array
     */
    private function getDaysOverdueDistribution($from, $to): array
    {
        $distribution = DB::table('tbl_LitPhoneCollection')
            ->whereNull('deletedAt')
            ->whereBetween(DB::raw('"assignedAt"'), [$from, $to])
            ->selectRaw('
                CASE
                    WHEN "daysOverdueGross" BETWEEN 0 AND 30 THEN \'0-30\'
                    WHEN "daysOverdueGross" BETWEEN 31 AND 60 THEN \'31-60\'
                    WHEN "daysOverdueGross" BETWEEN 61 AND 90 THEN \'61-90\'
                    WHEN "daysOverdueGross" BETWEEN 91 AND 120 THEN \'91-120\'
                    ELSE \'120+\'
                END as range,
                COUNT(*) as count,
                COALESCE(SUM("totalOvdAmount"), 0) as "totalAmount"
            ')
            ->groupBy(DB::raw('
                CASE
                    WHEN "daysOverdueGross" BETWEEN 0 AND 30 THEN \'0-30\'
                    WHEN "daysOverdueGross" BETWEEN 31 AND 60 THEN \'31-60\'
                    WHEN "daysOverdueGross" BETWEEN 61 AND 90 THEN \'61-90\'
                    WHEN "daysOverdueGross" BETWEEN 91 AND 120 THEN \'91-120\'
                    ELSE \'120+\'
                END
            '))
            ->orderBy(DB::raw("
                MIN(CASE
                    WHEN \"daysOverdueGross\" BETWEEN 0 AND 30 THEN 1
                    WHEN \"daysOverdueGross\" BETWEEN 31 AND 60 THEN 2
                    WHEN \"daysOverdueGross\" BETWEEN 61 AND 90 THEN 3
                    WHEN \"daysOverdueGross\" BETWEEN 91 AND 120 THEN 4
                    ELSE 5
                END)
            "))
            ->get()
            ->map(function ($item) {
                return [
                    'range' => $item->range,
                    'count' => (int) $item->count,
                    'totalAmount' => (int) $item->totalAmount,
                ];
            });

        return $distribution->toArray();
    }

    /**
     * Get reschedule statistics
     * Note: Litigation module doesn't have rescheduling feature yet
     *
     * @param Carbon $from
     * @param Carbon $to
     * @return array
     */
    private function getRescheduleStats($from, $to): array
    {
        return [
            'rescheduled' => 0,
            'immediate' => 0,
        ];
    }
}
