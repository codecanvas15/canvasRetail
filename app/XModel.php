<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

use DateTimeInterface;

class XModel extends Model
{
    //
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
