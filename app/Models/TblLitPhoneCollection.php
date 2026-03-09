<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TblLitPhoneCollection extends Model
{
    protected $table = 'tbl_LitPhoneCollection';
    protected $primaryKey = 'litPhoneCollectionId';
    public $timestamps = false;

    protected $fillable = [
        'litCaseId',
        'status',
        'contractId',
        'customerId',
        'paymentId',
        'paymentNo',
        'assetId',
        'dueDate',
        'daysOverdueGross',
        'daysOverdueNet',
        'daysSinceLastPayment',
        'totalOvdAmount',
        'contractNo',
        'contractDate',
        'contractType',
        'contractingProductType',
        'customerFullName',
        'gender',
        'birthDate',
        'cycleId',
        'hasKYCAppAccount',
        'customerAge',
        'contractPlaceName',
        'salesAreaName',
        'productName',
        'productColor',
        'plateNo',
        'unitPrice',
        'paymentStatus',
        'phoneNo1',
        'phoneNo2',
        'phoneNo3',
        'homeAddress',
        'salesAreaId',
        'contractPlaceId',
        'lastPaymentDate',
        'beelineDistance',
        'deviceControlProvider',

        // System fields (for internal use)
        'assignedTo',
        'assignedBy',
        'assignedAt',
        'totalAttempts',
        'lastAttemptAt',
        'lastAttemptBy',
        'createdAt',
        'createdBy',
        'updatedAt',
        'updatedBy',
        'deletedAt',
        'deletedBy',
        'deletedReason',
        'completedBy',
        'completedAt',
    ];

    protected $casts = [
        'dueDate' => 'date',
        'contractDate' => 'date',
        'birthDate' => 'date',
        'lastPaymentDate' => 'date',
        'assignedAt' => 'datetime',
        'lastAttemptAt' => 'datetime',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
        'deletedAt' => 'datetime',
        'completedAt' => 'datetime',
        'hasKYCAppAccount' => 'boolean',
        'beelineDistance' => 'float',
    ];

    // Scopes
    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deletedAt');
    }

    public function scopeForCycle($query, $cycleId)
    {
        return $query->where('cycleId', $cycleId);
    }

    public function scopeAssignedTo($query, $authUserId)
    {
        return $query->where('assignedTo', $authUserId);
    }

    // Relationships
    public function cycle()
    {
        return $this->belongsTo(TblLitCycle::class, 'cycleId', 'cycleId');
    }

    public function assignedToUser()
    {
        return $this->belongsTo(UserReference::class, 'assignedTo', 'authUserId');
    }

    public function assignedByUser()
    {
        return $this->belongsTo(UserReference::class, 'assignedBy', 'authUserId');
    }

    public function creator()
    {
        return $this->belongsTo(UserReference::class, 'createdBy', 'authUserId');
    }

    public function updater()
    {
        return $this->belongsTo(UserReference::class, 'updatedBy', 'authUserId');
    }
}
