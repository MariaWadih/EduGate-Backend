<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Insight extends Model
{
    protected $fillable = ['insight_type', 'scope', 'severity', 'message', 'related_entity_id'];
}
