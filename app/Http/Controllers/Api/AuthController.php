<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    // User Register (POST, formdata)
    public function register(Request $request){

        // data validation
        $request->validate([
            "name" => "required",
            "username" => "required|unique:users",
            "role" => "required",
            "password" => "required"
        ]);

        // User Model
        User::create([
            "name" => $request->name,
            "username" => $request->username,
            "role" => $request->role,
            "password" => Hash::make($request->password),
            "created_by" => 0,
            "updated_by" => 0,
            "status" => 1,
        ]);

        // Response
        return response()->json([
            "status" => true,
            "message" => "User registered successfully"
        ]);
    }

    // User Login (POST, formdata)
    public function login(Request $request){

        // data validation
        $request->validate([
            "username" => "required",
            "password" => "required"
        ]);

        // JWTAuth
        $token = JWTAuth::attempt([
            "username" => $request->username,
            "password" => $request->password
        ]);

        if(!empty($token)){

            return response()->json([
                "status"    => true,
                "message"   => "User logged in succcessfully",
                "token"     => $token,
                "profile"   => [
                    "username"  => auth()->user()->username,
                    "name"      => auth()->user()->name,
                    "role"      => auth()->user()->role
                ]
            ]);
        }

        return response()->json([
            "status" => false,
            "message" => "Wrong Username Or Password"
        ], 401);
    }

    // User Profile (GET)
    public function profile(){

        $userdata = auth()->user();

        return response()->json([
            "status" => true,
            "message" => "Profile data",
            "data" => $userdata
        ]);
    }

    // To generate refresh token value
    public function refreshToken(){

        $newToken = auth()->refresh();

        return response()->json([
            "status" => true,
            "message" => "New access token",
            "token" => $newToken
        ]);
    }

    // User Logout (GET)
    public function logout(){

        auth()->logout();

        return response()->json([
            "status" => true,
            "message" => "User logged out successfully"
        ]);
    }
}
