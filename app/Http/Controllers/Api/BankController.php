<?php

namespace App\Http\Controllers\Api;

use App\Bank;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BankController extends Controller
{
    //

    public function getBank(Request $request)
    {
        $banks = Bank::where('status', 1)->get();

        return response()->json([
            "status"    => true,
            "data"      => $banks
        ]);
    }

    public function addBank(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "bank_name"  => "required",
            "account_name" => "required",
            "account_number" => "required"
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        Bank::create([
            'bank_name'         => $request->bank_name,
            'account_name'      => $request->account_name,
            'account_number'    => $request->account_number,
            'created_by'        => auth()->user()->id,
            'updated_by'        => auth()->user()->id,
            'status'            => 1
        ]);

        return response()->json([
            "status" => true,
            "message" => "Bank registered successfully"
        ]);
    }

    public function getBankById($id)
    {
        if (Bank::where('id', $id)->where('status', 1)->exists())
        {
            $bank = Bank::find($id);

            return response()->json([
                "status" => true,
                "data" => $bank
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Bank not found"
            ], 404);
        }
    }

    public function updateBank(Request $request, $id)
    {
        if (Bank::where('id', $id)->exists())
        {
            $bank = Bank::find($id);
            $bank->update([
                'bank_name'         => $request->bank_name,
                'account_name'      => $request->account_name,
                'account_number'    => $request->account_number,
                'updated_at'        => date("Y-m-d H:i:s"),
                'updated_by'        => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Bank updated successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Bank not found"
            ], 404);
        }
    }

    public function deleteBank($id)
    {
        if (Bank::where('id', $id)->exists())
        {
            $bank = Bank::find($id);
            $bank->update([
                'status'        => 0,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Bank deleted successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Bank not found"
            ], 404);
        }
    }
}
