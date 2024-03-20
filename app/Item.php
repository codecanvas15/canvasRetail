<?php

namespace App;

use App\XModel;

class Item extends XModel
{
    //
    protected $fillable = [
        'item_code',
        'name',
        'image',
        'category',
        'created_by',
        'updated_by',
        'status'
    ];
}
