<?php

namespace App;

use App\XModel;

class SalesDetail extends XModel
{
    //
    protected $table = 'sales_details';

    protected $fillable = [
        'sales_id',
        'item_detail_id',
        'qty',
        'price',
        'total',
        'tax_ids',
        'created_by',
        'updated_by',
        'status',
    ];
}
