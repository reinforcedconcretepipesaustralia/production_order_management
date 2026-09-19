<?php

namespace App\Models;

use Database\Factories\DumpReasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DumpReason extends Model
{
    /** @use HasFactory<DumpReasonFactory> */
    use HasFactory;

    public const TYPE_PIPE = 'pipe';

    public const TYPE_CAGE = 'cage';

    protected $fillable = ['code', 'description', 'product_type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
