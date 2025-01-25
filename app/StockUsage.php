<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockUsage extends Model
{
    use HasFactory;

    protected $table = 'stock_usage';

    protected $fillable = [
        'item_detail_id',
        'location_id',
        'user_item_name',
        'qty',
        'transaction_date',
        'reason',
        'created_by',
        'updated_by',
        'status',
        'doc_number'
    ];
}
