<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class submission_has_vote extends Model
{
    use HasFactory;

    public function submission() : HasOne
    {
        return $this->hasOne('App\Models\submission');
    }

    public function user() : HasOne
    {
        return $this->hasOne('App\Models\legacy_user','access_token','user_id');
    }

}
