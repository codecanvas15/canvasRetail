<?php

namespace App;

use App\XModel;

class Tax extends XModel
{
    //
    protected $fillable = [
        'name',
        'value',
        'created_by',
        'updated_by',
        'status'
    ];
}
