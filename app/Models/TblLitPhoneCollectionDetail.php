<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TblLitPhoneCollectionDetail extends Model
{
    protected $table = 'tbl_LitPhoneCollectionDetail';
    protected $primaryKey = 'litPhoneCollectionDetailId';
    public $timestamps = false;

    protected $fillable = [
        'litPhoneCollectionId',
        'contactType',
        'phoneId',
        'contactDetailId',
        'contactPhoneNumber',
        'contactName',
        'contactRelation',
        'callStatus',
        'caseResultId',
        'remark',
        'promisedPaymentDate',
        'dtCallStarted',
        'dtCallEnded',
        'createdAt',
        'createdBy',
        'updatedAt',
        'updatedBy',
        'deletedAt',
        'deletedby',
        'deletedReason',
        'promisedPaymentAmount',
        'claimedPaymentDate',
        'claimedPaymentAmount',
        'isUncontactable',
    ];

    protected $casts = [
        'promisedPaymentDate' => 'date',
        'claimedPaymentDate' => 'date',
        'dtCallStarted' => 'datetime',
        'dtCallEnded' => 'datetime',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
        'deletedAt' => 'datetime',
        'isUncontactable' => 'boolean',
    ];

    // Relationships
    public function phoneCollection()
    {
        return $this->belongsTo(TblLitPhoneCollection::class, 'litPhoneCollectionId', 'litPhoneCollectionId');
    }

    public function caseResult()
    {
        return $this->belongsTo(TblLitCaseResult::class, 'caseResultId', 'caseResultId');
    }

    public function creator()
    {
        return $this->belongsTo(UserReference::class, 'createdBy', 'authUserId');
    }
}
