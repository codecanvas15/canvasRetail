<?php

namespace App;

use App\XModel;

class ProcurementDetail extends XModel
{
    //
    protected $table = 'procurement_details';

    protected $fillable = [
        'procurement_id',
        'item_detail_id',
        'qty',
        'price',
        'total',
        'tax_ids',
        'created_by',
        'updated_by',
        'status',
        'discount'
    ];
}
