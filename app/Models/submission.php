<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class submission extends Model
{
    use HasFactory;

    public function votes() : HasMany
    {
        return $this->hasMany('App\Models\submission_has_vote');
    }

    public function user() : HasOne
    {
        return $this->hasOne('App\Models\legacy_user','access_token','user_id');
    }
}
