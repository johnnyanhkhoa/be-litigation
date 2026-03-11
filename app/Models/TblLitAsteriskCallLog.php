<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TblLitAsteriskCallLog extends Model
{
    protected $table = 'tbl_LitAsteriskCallLog';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'caseId',
        'phoneNo',
        'phoneExtension',
        'userId',
        'username',
        'createdAt',
        'createdBy',
        'updatedAt',
        'updatedBy',
        'deletedAt',
        'deletedBy',
        'deletedReason',
        'apiCallId',
        'callDate',
        'calledAt',
        'handleTimeSec',
        'talkTimeSec',
        'callStatus',
        'recordFile',
        'asteriskCallId',
        'rawContent',
        'company',
        'outboundCnum',
        'callFrom',
        'caseDetailId',
    ];

    protected $casts = [
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
        'deletedAt' => 'datetime',
        'calledAt' => 'datetime',
        'callDate' => 'date',
    ];

    // Relationship
    public function phoneCollection()
    {
        return $this->belongsTo(TblLitPhoneCollection::class, 'caseId', 'litPhoneCollectionId');
    }

    public function phoneCollectionDetail()
    {
        return $this->belongsTo(TblLitPhoneCollectionDetail::class, 'caseDetailId', 'litPhoneCollectionDetailId');
    }
}
