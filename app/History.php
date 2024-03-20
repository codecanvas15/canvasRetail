<?php

namespace App;

use App\XModel;

class History extends XModel
{
    //
    protected $fillable = [
        'module',
        'action',
        'description',
        'created_by',
    ];
}
