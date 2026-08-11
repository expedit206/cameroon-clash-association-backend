<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'fee',
        'net_amount',
        'phone_number',
        'payment_method',
        'notchpay_reference',
        'status',
        'admin_note',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'amount'       => 'integer',
        'fee'          => 'integer',
        'net_amount'   => 'integer',
        'processed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Scope: retraits en attente de traitement admin.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
