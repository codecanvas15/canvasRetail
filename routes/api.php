<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\SalesController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// url/api/method
Route::post("login", [AuthController::class, "login"]);
Route::post("register", [AuthController::class, "register"]);

Route::group([
    'middleware' => 'auth:api'
], function(){
    // auth
    // Route::get("refreshToken", [AuthController::class, "refreshToken"]);
    Route::get("profile", [AuthController::class, "profile"]);
    Route::get("logout", [AuthController::class, "logout"]);

    // masters
    // User

    // item
    Route::post("item",[ItemController::class, "addItem"]);
    Route::get("item",[ItemController::class, "getItem"]);
    Route::get("item/{item_code}",[ItemController::class, "getItemById"]);
    Route::post("item/{item_code}",[ItemController::class, "updateItem"]);
    Route::delete("item/{item_code}",[ItemController::class, "deleteItem"]);

    // location
    Route::post("location",[LocationController::class, "addLocation"]);
    Route::get("location",[LocationController::class, "getLocation"]);
    Route::get("location/{id}",[LocationController::class, "getLocationById"]);
    Route::post("location/{id}",[LocationController::class, "updateLocation"]);
    Route::delete("location/{id}",[LocationController::class, "deleteLocation"]);

    // tax
    Route::post("tax",[TaxController::class, "addTax"]);
    Route::get("tax",[TaxController::class, "getTax"]);
    Route::get("tax/{id}",[TaxController::class, "getTaxById"]);
    Route::post("tax/{id}",[TaxController::class, "updateTax"]);
    Route::delete("tax/{id}",[TaxController::class, "deleteTax"]);

    // contact
    Route::post("contact",[ContactController::class, "addContact"]);
    Route::get("contact",[ContactController::class, "getContact"]);
    Route::get("contact/{id}",[ContactController::class, "getContactById"]);
    Route::post("contact/{id}",[ContactController::class, "updateContact"]);
    Route::delete("contact/{id}",[ContactController::class, "deleteContact"]);

    // transactions
    // procurement
    Route::post("procurement", [ProcurementController::class, "addProcurement"]);
    Route::post("procurement/{id}", [ProcurementController::class, "updateProcurement"]);
    Route::get("procurement", [ProcurementController::class, "getProcurement"]);
    Route::get("procurement/{id}", [ProcurementController::class, "getProcurementById"]);
    Route::delete("procurement/{id}", [ProcurementController::class, "deleteProcurement"]);
    Route::get("generatePo", [ProcurementController::class, "createPO"]);

    // sales
    Route::post("sales", [SalesController::class, "addSales"]);
    Route::post("sales/{id}", [SalesController::class, "updateSales"]);
    Route::get("sales", [SalesController::class, "getSales"]);
    Route::get("sales/{id}", [SalesController::class, "getSalesById"]);
    Route::delete("sales/{id}", [SalesController::class, "deleteSales"]);

    // payment
    Route::post("payment", [PaymentController::class, "payment"]);
    Route::get("payment", [PaymentController::class, "getPayment"]);

    // refund
    Route::post("refund", [RefundController::class, "refund"]);
    Route::get("refund", [RefundController::class, "getRefund"]);

    // reports
});

