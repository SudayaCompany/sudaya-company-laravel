<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Brand extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'code', 'name'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
