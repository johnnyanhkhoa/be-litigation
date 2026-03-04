<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TblLitControllerLto extends Model
{
    protected $table = 'tbl_LitControllerLto';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'cycleId',
        'controllerId',
        'ltoId',
        'active',
        'remark',
        'createdAt',
        'createdBy',
        'updatedAt',
        'updatedBy',
        'deletedAt',
        'deletedBy',
    ];

    protected $casts = [
        'active' => 'boolean',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
        'deletedAt' => 'datetime',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deletedAt');
    }

    public function scopeForCycle($query, $cycleId)
    {
        return $query->where('cycleId', $cycleId);
    }

    // Relationships
    public function cycle()
    {
        return $this->belongsTo(TblLitCycle::class, 'cycleId', 'cycleId');
    }

    public function controller()
    {
        return $this->belongsTo(UserReference::class, 'controllerId', 'authUserId');
    }

    public function lto()
    {
        return $this->belongsTo(UserReference::class, 'ltoId', 'authUserId');
    }

    public function creator()
    {
        return $this->belongsTo(UserReference::class, 'createdBy', 'authUserId');
    }

    public function updater()
    {
        return $this->belongsTo(UserReference::class, 'updatedBy', 'authUserId');
    }

    public function deleter()
    {
        return $this->belongsTo(UserReference::class, 'deletedBy', 'authUserId');
    }
}
