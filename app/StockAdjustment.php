<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $table = 'stock_adjustment';

    protected $fillable = [
        'item_code',
        'location_id',
        'qty',
        'transaction_date',
        'created_by',
        'updated_by',
        'status'
    ];

}

