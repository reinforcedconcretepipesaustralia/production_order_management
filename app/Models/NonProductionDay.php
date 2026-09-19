<?php

namespace App\Models;

use Database\Factories\NonProductionDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NonProductionDay extends Model
{
    /** @use HasFactory<NonProductionDayFactory> */
    use HasFactory;

    protected $fillable = ['depot_id', 'date', 'category', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }
}
