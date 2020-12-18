<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class sms extends Model
{
    use HasFactory;
    protected $table = 'SMS_Log';
    protected $primaryKey = 'twilio_sid';
    protected $fillable = ['twilio_sid','timestamp','from_number','to_number','message_sent','message_received','message_body','log_id','dh_read'];
    public $timestamps = false;

    public function scopeUnread($query)
    {
        return $query->where('dh_read',0)->where('to_number',env('twilio_bulk_number'));
    }

    public function has_log() : \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne('App\Models\log','log_id','log_id');
    }

    public function belongs_to_person() : \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne('App\Models\employee','phone_number','from_number');
    }

}
