<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $fillable = ['code', 'description'];
}
