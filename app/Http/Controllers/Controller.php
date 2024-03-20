<?php

namespace App\Http\Controllers;

use App\History;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function history($module, $action, $description)
    {
        History::create([
            'module' => strtoupper($module),
            'action' => strtoupper($action),
            'description' => $description,
            'created_by' => auth()->user()->id,
        ]);
    }
}
