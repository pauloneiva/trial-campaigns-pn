<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = ['subject', 'body', 'contact_list_id', 'status', 'scheduled_at'];

    protected $attributes = [
        'status' => 'draft',
    ];


    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function contactList(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }

    public function sends(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }

    public function getStatsAttribute(): array
    {
        $counts = $this->sends()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => $counts['pending'] ?? 0,
            'sent'    => $counts['sent'] ?? 0,
            'failed'  => $counts['failed'] ?? 0,
            'total'   => $counts->sum(),
        ];
    }
}
