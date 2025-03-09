<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportQueue extends Model
{
    use HasFactory;

    protected $table = 'report_queue';

    protected $fillable = [
        'type',
        'start_date',
        'end_date',
        'status',
        'file'
    ];
}
