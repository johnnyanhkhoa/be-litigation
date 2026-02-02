<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TblLitCycle extends Model
{
    protected $table = 'tbl_LitCycle';
    protected $primaryKey = 'cycleId';
    public $timestamps = false;

    protected $fillable = [
        'cycleName',
        'cycleDateFrom',
        'cycleDateTo',
        'cycleActive',
        'deactivatedAt',
        'deactivatedBy',
        'cycleWriteOffActive',
        'cycleAmcContractActive',
        'cycleRemark',
        'createdAt',
        'createdBy',
        'updatedAt',
        'updatedBy',
        'deletedAt',
        'deletedBy',
    ];

    protected $casts = [
        'cycleActive' => 'boolean',
        'cycleWriteOffActive' => 'boolean',
        'cycleAmcContractActive' => 'boolean',
        'cycleDateFrom' => 'date',
        'cycleDateTo' => 'date',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
        'deletedAt' => 'datetime',
        'deactivatedAt' => 'datetime',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('cycleActive', true);
    }

    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deletedAt');
    }

    // Relationships - Dùng authUserId để join
    public function creator()
    {
        return $this->belongsTo(UserReference::class, 'createdBy', 'authUserId');
    }

    public function updater()
    {
        return $this->belongsTo(UserReference::class, 'updatedBy', 'authUserId');
    }

    public function deactivator()
    {
        return $this->belongsTo(UserReference::class, 'deactivatedBy', 'authUserId');
    }

    public function deleter()
    {
        return $this->belongsTo(UserReference::class, 'deletedBy', 'authUserId');
    }
}
