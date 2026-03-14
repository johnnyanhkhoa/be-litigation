<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TblLitExportToEmail extends Model
{
    protected $table = 'tbl_LitExportToEmail';
    protected $primaryKey = 'exportToEmailId';
    public $timestamps = false;

    protected $fillable = [
        'reportType',
        'email',
        'isActive',
        'createdAt',
        'createdBy',
        'updatedAt',
        'updatedBy',
        'deletedAt',
        'deletedBy',
        'deletedReason',
    ];

    protected $casts = [
        'isActive' => 'boolean',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
        'deletedAt' => 'datetime',
    ];

    // Scopes
    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deletedAt');
    }

    public function scopeActive($query)
    {
        return $query->where('isActive', true);
    }

    public function scopeForReportType($query, string $reportType)
    {
        return $query->where('reportType', $reportType);
    }

    // Relationships
    public function creator()
    {
        return $this->belongsTo(UserReference::class, 'createdBy', 'authUserId');
    }
}
