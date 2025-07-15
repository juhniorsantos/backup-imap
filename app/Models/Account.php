<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'user',
        'password',
        'completed'
    ];

    protected $casts = [
        'completed' => 'datetime',
    ];

    public function mails(): HasMany
    {
        return $this->hasMany(Mail::class);
    }
}
