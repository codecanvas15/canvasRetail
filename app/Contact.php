<?php

namespace App;

use App\XModel;

class Contact extends XModel
{
    //
    protected $fillable = [
        'name',
        'type',
        'address',
        'phone',
        'email',
        'behalf',
        'created_by',
        'updated_by',
        'status'
    ];
}
