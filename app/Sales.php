<?php

namespace App;

use App\XModel;

class Sales extends XModel
{
    //
    protected $table = 'sales';

    protected $fillable = [
        'contact_id',
        'sales_date',
        'amount',
        'pay_status',
        'delivery_status',
        'created_by',
        'updated_by',
        'status',
        'doc_number',
        'rounding',
        'bank_id',
        'tax',
        'location_id',
        'reason',
        'due_date',
    ];
}
