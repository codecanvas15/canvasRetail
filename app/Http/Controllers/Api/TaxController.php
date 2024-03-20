<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tax;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    //
    public function addTax(Request $request)
    {
        $request->validate([
            "name"  => "required",
            "value" => "required"
        ]);

        Tax::create([
            'name'          => $request->name,
            'value'          => $request->value,
            'created_by'    => auth()->user()->id,
            'updated_by'    => auth()->user()->id,
            'status'        => 1
        ]);

        return response()->json([
            "status" => true,
            "message" => "Tax registered successfully"
        ]);
    }

    public function getTax()
    {
        $taxs = Tax::where('status', 1)->get();

        return response()->json([
            "status" => true,
            "data" => $taxs
        ]);
    }

    public function updateTax(Request $request, $id)
    {
        if (Tax::where('id', $id)->exists())
        {
            $tax = Tax::find($id);
            $tax->update([
                'name'          => $request->name,
                'value'          => $request->value,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Tax updated successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Tax not found"
            ], 404);
        }
    }

    public function deleteTax($id)
    {
        if (Tax::where('id', $id)->exists())
        {
            $tax = Tax::find($id);
            $tax->update([
                'status'        => 0,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Tax deleted successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Tax not found"
            ], 404);
        }
    }

    public function getTaxById($id)
    {
        if (Tax::where('id', $id)->where('status', 1)->exists())
        {
            $tax = Tax::find($id);

            return response()->json([
                "status" => true,
                "data" => $tax
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Tax not found"
            ], 404);
        }
    }
}