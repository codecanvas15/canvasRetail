<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    //\

    public function getUser(Request $request)
    {
        $user = User::where('status', 1)->get();

        return response()->json([
            "status" => true,
            "data" => $user
        ]);
    }

    public function addUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "username" => "required|unique:users",
            "name" => "required",
            "role" => "required",
            "password" => "required"
        ]);

        if ($validator->fails()) {
            $errorMsg = '';
            
            foreach ($validator->errors()->all() as $error)
            {
                $errorMsg .= $error . '<br>';
            }
            
            return response()->json([
                "status" => false,
                "message" => $errorMsg
            ], 400);
        }

        User::create([
            "username" => $request->username,
            "name" => $request->name,
            "role" => $request->role,
            "password" => Hash::make($request->password),
            "created_by" => auth()->user()->id,
            "updated_by" => auth()->user()->id,
            "status" => 1,
        ]);

        // Response
        return response()->json([
            "status" => true,
            "message" => "User registered successfully"
        ]);
    }

    public function updateUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "user_id" => "required",
        ]);

        if ($validator->fails()) {
            $errorMsg = '';
            
            foreach ($validator->errors()->all() as $error)
            {
                $errorMsg .= $error . '<br>';
            }
            
            return response()->json([
                "status" => false,
                "message" => $errorMsg
            ], 400);
        }

        $user = User::where('id', $request->user_id)->first();

        $user->update([
            "name" => $request->name ?? auth()->user()->name,
            "role" => $request->role ?? auth()->user()->role,
            "password" => Hash::make($request->password) ?? auth()->user()->password,
            "updated_by" => auth()->user()->id,
            "status" => 1,
        ]);

        // Response
        return response()->json([
            "status" => true,
            "message" => "User updated successfully"
        ]);
    }

    public function deleteUser(Request $request, $id)
    {
        $user = User::where('id', $id)->where('status', 1)->first();

        if (!$user) 
        {
            return response()->json([
                "status" => false,
                "message" => "User not found"
            ], 404);
        }

        $user->update([
            "updated_by" => auth()->user()->id,
            "status" => 0,
        ]);

        // Response
        return response()->json([
            "status" => true,
            "message" => "User deleted successfully"
        ]);
    }

    public function getUserById($id)
    {
        $user = User::where('id', $id)->where('status', 1)->first();

        if (!$user) 
        {
            return response()->json([
                "status" => false,
                "message" => "User not found"
            ], 404);
        }

        return response()->json([
            "status" => true,
            "data" => $user
        ]);
    }
}
