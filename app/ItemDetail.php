<?php

namespace App;

use App\XModel;

class ItemDetail extends XModel
{
    //
    protected $table = 'items_details';

    protected $fillable = [
        'item_code',
        'location_id',
        'qty',
        'price',
        'created_by',
        'updated_by',
        'status',
    ];
}
