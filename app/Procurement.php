<?php

namespace App;

use App\XModel;

class Procurement extends XModel
{
    //
    protected $fillable = [
        'contact_id',
        'procurement_date',
        'amount',
        'pay_status',
        'delivery_status',
        'created_by',
        'updated_by',
        'status',
    ];
}
