<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockUsageHeader extends Model
{
    use HasFactory;

    protected $table = 'stock_usage_header';

    protected $fillable = [
        'transaction_date',
        'reason',
        'doc_number',
        'user_item_name',
        'created_by',
        'updated_by',
        'status'
    ];
}
