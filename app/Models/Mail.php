<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mail extends Model
{
    protected $fillable = [
        'account_id',
        'uuid',
        'message_number',
        'folder',
        'downloaded',
        'filename'
    ];

    protected $casts = [
        'downloaded' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
