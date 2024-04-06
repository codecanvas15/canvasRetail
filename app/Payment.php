<?php

namespace App;

use App\XModel;

class Payment extends XModel
{
    //
    protected $table = 'payment';

    protected $fillable = [
        'procurement_id',
        'sales_id',
        'type',
        'amount',
        'pay_date',
        'created_by',
        'updated_by',
        'status',
        'pay_desc'
    ];
}
