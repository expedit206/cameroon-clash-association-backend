<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClashBetAudit extends Model
{
    use HasFactory;

    public $timestamps = false; // Seul created_at est utilisé via default SQL timestamp

    protected $fillable = [
        'admin_id',
        'event_type',
        'market_id',
        'ticket_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function market()
    {
        return $this->belongsTo(BetMarket::class, 'market_id');
    }

    public function ticket()
    {
        return $this->belongsTo(BetTicket::class, 'ticket_id');
    }
}
