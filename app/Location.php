<?php

namespace App;

use App\XModel;

class Location extends XModel
{
    //
    protected $fillable = [
        'name',
        'created_by',
        'updated_by',
        'status'
    ];
}
