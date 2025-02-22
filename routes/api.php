<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockCardController;
use App\Http\Controllers\Api\StockUsageController;
use App\StockUsage;

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
Route::get("logout", [AuthController::class, "logout"]);

Route::group([
    'middleware' => 'auth:api'
], function(){
    // auth
    Route::get("refreshToken", [AuthController::class, "refreshToken"]);
    Route::get("profile", [AuthController::class, "profile"]);

    // masters
    // User

    // item
    Route::post("item",[ItemController::class, "addItem"]);
    Route::get("item",[ItemController::class, "getItem"]);
    Route::get("itemdetail",[ItemController::class, "getItemById"]);
    Route::post("updateitem",[ItemController::class, "updateItem"]);
    Route::delete("item",[ItemController::class, "deleteItem"]);

    // get categories
    Route::get("categories",[ItemController::class, "getUniqueCategories"]);

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

    // bank
    Route::post("bank", [BankController::class, "addBank"]);
    Route::get("bank", [BankController::class, "getBank"]);
    Route::get("bank/{id}", [BankController::class, "getBankById"]);
    Route::post("bank/{id}", [BankController::class, "updateBank"]);
    Route::delete("bank/{id}", [BankController::class, "deleteBank"]);

    // transactions
    // procurement
    Route::post("procurement", [ProcurementController::class, "addProcurement"]);
    Route::post("procurement/{id}", [ProcurementController::class, "updateProcurement"]);
    Route::get("procurement", [ProcurementController::class, "getProcurement"]);
    Route::get("procurement/{id}", [ProcurementController::class, "getProcurementById"]);
    Route::delete("procurement/{id}", [ProcurementController::class, "deleteProcurement"]);
    Route::get("generatePo", [ProcurementController::class, "createPO"]);
    Route::get("procurement-item-detail", [ProcurementController::class, "getItemProcurement"]);
    
    // sales
    Route::post("sales", [SalesController::class, "addSales"]);
    Route::post("sales/{id}", [SalesController::class, "updateSales"]);
    Route::get("sales", [SalesController::class, "getSales"]);
    Route::get("sales/{id}", [SalesController::class, "getSalesById"]);
    Route::delete("sales/{id}", [SalesController::class, "deleteSales"]);
    Route::get("sales-item-detail", [SalesController::class, "getItemSales"]);
    Route::get("faktur", [SalesController::class, "faktur"]);

    // payment
    Route::post("payment", [PaymentController::class, "payment"]);
    Route::get("payment", [PaymentController::class, "getPayment"]);
    Route::get("pay-receipt", [PaymentController::class, "payReceipt"]);

    // refund
    Route::post("refund", [RefundController::class, "refund"]);
    Route::get("refund", [RefundController::class, "getRefund"]);

    // stock opname
    // stock adjustment
    Route::post("stockadjustment", [StockAdjustmentController::class, "adjustment"]);
    Route::get("stockadjustment", [StockAdjustmentController::class, "getAdjustment"]);
    Route::get("stockadjustment/{id}", [StockAdjustmentController::class, "getAdjustmentDetail"]);
    Route::post("stockadjustment/reject/{id}", [StockAdjustmentController::class, "rejectAdjustment"]);
    
    // stock usage
    Route::post("stockusage", [StockUsageController::class, "usage"]);
    Route::get("stockusage", [StockUsageController::class, "getUsage"]);
    Route::get("stockusage/{id}", [StockUsageController::class, "getUsageDetail"]);
    Route::post("stockusage/reject/{id}", [StockUsageController::class, "rejectUsage"]);

    // reports
    // stockcard
    Route::get("stockcardList", [StockCardController::class, "getStockCardList"]);
    Route::get("stockcardDetails", [StockCardController::class, "getStockCardDetails"]);

});

