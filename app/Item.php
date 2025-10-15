<?php

namespace App;

use App\XModel;

class Item extends XModel
{
    //
    protected $primaryKey = 'item_code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'item_code',
        'name',
        'image',
        'category',
        'created_by',
        'updated_by',
        'status',
        'unit'
    ];
}
