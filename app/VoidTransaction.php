<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoidTransaction extends Model
{
    use HasFactory;

    protected $table = 'void_transaction';

    protected $fillable = [
        'ref_id',
        'sales_id',
        'procurement_id',
        'adjustment_id',
        'usage_id',
        'reason',
        'status',
        'updated_by',
        'updated_at'
    ];
}
