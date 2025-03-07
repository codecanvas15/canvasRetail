<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoidDetails extends Model
{
    use HasFactory;

    protected $table = 'void_detail';

    protected $fillable = [
        'void_id',
        'item_detail_id',
        'qty',
        'updated_by',
        'updated_at',
        'status'
    ];
}
