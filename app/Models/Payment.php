<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'clan_registration_id',
        'amount',
        'currency',
        'reference',
        'status',
        'confirmed_by',
        'confirmed_at',
        'payment_method',
        'phone_number',
        'notes',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'amount' => 'integer',
    ];

    /**
     * Get the registration associated with this payment.
     */
    public function registration()
    {
        return $this->belongsTo(ClanRegistration::class, 'clan_registration_id');
    }

    /**
     * Get the admin who confirmed the payment.
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
