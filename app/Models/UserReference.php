<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReference extends Model
{
    protected $table = 'user_references';
    protected $primaryKey = 'id';
    public $incrementing = true; // ← Đổi thành true vì id là số tự tăng
    protected $keyType = 'integer'; // ← Đổi thành integer

    protected $fillable = [
        'id',
        'authUserId',
        'email',
        'username',
        'userFullName',
        'isActive',
        'extensionNo',
        'level',
        'synced_at',
    ];

    protected $casts = [
        'authUserId' => 'integer',
        'isActive' => 'boolean',
        'synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('isActive', true);
    }
}
