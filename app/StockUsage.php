<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockUsage extends Model
{
    use HasFactory;

    protected $table = 'stock_usage';

    protected $fillable = [
        'stock_usage_id',
        'item_detail_id',
        'qty',
        'created_by',
        'updated_by',
        'status'
    ];
}
