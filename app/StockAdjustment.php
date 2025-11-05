<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory;

    protected $table = 'stock_adjustment';

    protected $fillable = [
        'stock_adjustment_id',
        'item_detail_id',
        'qty',
        'price',
        'total',
        'created_by',
        'updated_by',
        'status'
    ];

}

