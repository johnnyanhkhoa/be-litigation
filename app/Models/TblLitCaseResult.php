<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TblLitCaseResult extends Model
{
    protected $table = 'tbl_LitCaseResult';
    protected $primaryKey = 'caseResultId';
    public $timestamps = false;

    protected $fillable = [
        'caseResultName',
        'caseResultRemark',
        'caseResultActive',
        'createdAt',
        'createdBy',
        'updatedAt',
        'updatedBy',
        'deletedAt',
        'deletedBy',
        'deactivatedAt',
        'deactivatedBy',
        'requiredField',
    ];

    protected $casts = [
        'caseResultActive' => 'boolean',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
        'deletedAt' => 'datetime',
        'deactivatedAt' => 'datetime',
        'requiredField' => 'array',
    ];
}
