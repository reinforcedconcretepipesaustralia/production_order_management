<?php

namespace App\Models;

use Database\Factories\DumpCauseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DumpCause extends Model
{
    /** @use HasFactory<DumpCauseFactory> */
    use HasFactory;

    protected $fillable = ['description', 'category', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
