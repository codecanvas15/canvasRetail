<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustmentHeader extends Model
{
    use HasFactory;

    protected $table = 'stock_adjustment_header';

    protected $fillable = [
        'transaction_date',
        'reason',
        'doc_number',
        'created_by',
        'updated_by',
        'status'
    ];
}
