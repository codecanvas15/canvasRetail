<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_name',
        'account_name',
        'account_number',
        'created_by',
        'updated_by',
        'status',
    ];
}
