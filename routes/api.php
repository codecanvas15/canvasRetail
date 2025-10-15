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
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReportController;
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
    Route::get("refreshToken", [AuthController::class, "refreshToken"])->middleware('role:owner');
    Route::get("profile", [AuthController::class, "profile"])->middleware('role:owner');
    
    // masters
    // User
    Route::get("user", [UserController::class, "getUser"])->middleware('role:owner,admin');
    Route::get("user/{id}", [UserController::class, "getUserById"])->middleware('role:owner,admin');
    Route::post("user", [UserController::class, "addUser"])->middleware('role:owner,admin');
    Route::post("user-update", [UserController::class, "updateUser"])->middleware('role:owner,admin');
    Route::delete("user/{id}", [UserController::class, "deleteUser"])->middleware('role:owner');

    // item
    Route::post("item",[ItemController::class, "addItem"])->middleware('role:owner,admin');
    Route::get("item",[ItemController::class, "getItem"])->middleware('role:owner,admin');
    Route::get("itemdetail",[ItemController::class, "getItemById"])->middleware('role:owner,admin');
    Route::post("updateitem",[ItemController::class, "updateItem"])->middleware('role:owner,admin');
    Route::delete("item",[ItemController::class, "deleteItem"])->middleware('role:owner');

    // get categories
    Route::get("categories",[ItemController::class, "getUniqueCategories"])->middleware('role:owner,admin');

    // get units
    Route::get("units",[ItemController::class, "getUniqueUnits"])->middleware('role:owner,admin');

    // location
    Route::post("location",[LocationController::class, "addLocation"])->middleware('role:owner,admin');
    Route::get("location",[LocationController::class, "getLocation"])->middleware('role:owner,admin');
    Route::get("location/{id}",[LocationController::class, "getLocationById"])->middleware('role:owner,admin');
    Route::post("location/{id}",[LocationController::class, "updateLocation"])->middleware('role:owner,admin');
    Route::delete("location/{id}",[LocationController::class, "deleteLocation"])->middleware('role:owner');

    // tax
    Route::post("tax",[TaxController::class, "addTax"])->middleware('role:owner,admin');
    Route::get("tax",[TaxController::class, "getTax"])->middleware('role:owner,admin');
    Route::get("tax/{id}",[TaxController::class, "getTaxById"])->middleware('role:owner,admin');
    Route::post("tax/{id}",[TaxController::class, "updateTax"])->middleware('role:owner,admin');
    Route::delete("tax/{id}",[TaxController::class, "deleteTax"])->middleware('role:owner');

    // contact
    Route::post("contact",[ContactController::class, "addContact"])->middleware('role:owner,admin');
    Route::get("contact",[ContactController::class, "getContact"])->middleware('role:owner,admin');
    Route::get("contact/{id}",[ContactController::class, "getContactById"])->middleware('role:owner,admin');
    Route::post("contact/{id}",[ContactController::class, "updateContact"])->middleware('role:owner,admin');
    Route::delete("contact/{id}",[ContactController::class, "deleteContact"])->middleware('role:owner');

    // bank
    Route::post("bank", [BankController::class, "addBank"])->middleware('role:owner,admin');
    Route::get("bank", [BankController::class, "getBank"])->middleware('role:owner,admin');
    Route::get("bank/{id}", [BankController::class, "getBankById"])->middleware('role:owner,admin');
    Route::post("bank/{id}", [BankController::class, "updateBank"])->middleware('role:owner,admin');
    Route::delete("bank/{id}", [BankController::class, "deleteBank"])->middleware('role:owner');

    // transactions
    // procurement
    Route::post("procurement", [ProcurementController::class, "addProcurement"])->middleware('role:owner,admin');
    Route::post("procurement/{id}", [ProcurementController::class, "updateProcurement"])->middleware('role:owner,admin');
    Route::get("procurement", [ProcurementController::class, "getProcurement"])->middleware('role:owner,admin');
    Route::get("procurement/{id}", [ProcurementController::class, "getProcurementById"])->middleware('role:owner,admin');
    Route::delete("procurement/{id}", [ProcurementController::class, "deleteProcurement"])->middleware('role:owner');
    Route::get("generatePo", [ProcurementController::class, "createPO"])->middleware('role:owner,admin');
    Route::get("procurement-item-detail", [ProcurementController::class, "getItemProcurement"])->middleware('role:owner,admin');
    Route::post("approve/procurement", [ProcurementController::class, "approveProcurement"])->middleware('role:owner');
    Route::post("void/procurement", [ProcurementController::class, "void"])->middleware('role:owner');
    
    // sales
    Route::post("sales", [SalesController::class, "addSales"])->middleware('role:owner,admin');
    Route::post("sales/{id}", [SalesController::class, "updateSales"])->middleware('role:owner,admin');
    Route::get("sales", [SalesController::class, "getSales"])->middleware('role:owner,admin');
    Route::get("sales/{id}", [SalesController::class, "getSalesById"])->middleware('role:owner,admin');
    Route::delete("sales/{id}", [SalesController::class, "deleteSales"])->middleware('role:owner');
    Route::get("sales-item-detail", [SalesController::class, "getItemSales"])->middleware('role:owner,admin');
    Route::get("faktur", [SalesController::class, "faktur"])->middleware('role:owner,admin');
    Route::post("approve/sales", [SalesController::class, "approveSales"])->middleware('role:owner');
    Route::post("void/sales", [SalesController::class, "void"])->middleware('role:owner');

    // payment
    Route::post("payment", [PaymentController::class, "payment"])->middleware('role:owner,admin');
    Route::get("payment", [PaymentController::class, "getPayment"])->middleware('role:owner,admin');
    Route::get("pay-receipt", [PaymentController::class, "payReceipt"])->middleware('role:owner,admin');

    // refund
    Route::post("refund", [RefundController::class, "refund"])->middleware('role:owner,admin');
    Route::get("refund", [RefundController::class, "getRefund"])->middleware('role:owner,admin');

    // stock opname
    // stock adjustment
    Route::post("stockadjustment", [StockAdjustmentController::class, "adjustment"])->middleware('role:owner,admin');
    Route::get("stockadjustment", [StockAdjustmentController::class, "getAdjustment"])->middleware('role:owner,admin');
    Route::get("stockadjustment/{id}", [StockAdjustmentController::class, "getAdjustmentDetail"])->middleware('role:owner,admin');
    Route::post("stockadjustment/{id}", [StockAdjustmentController::class, "updateAdjustment"])->middleware('role:owner,admin');
    // Route::post("stockadjustment/reject/{id}", [StockAdjustmentController::class, "rejectAdjustment"])->middleware('role:owner');
    Route::post("approve/stockadjustment", [StockAdjustmentController::class, "approveAdjustment"])->middleware('role:owner');
    Route::post("void/stockadjustment", [StockAdjustmentController::class, "void"])->middleware('role:owner');
    
    // stock usage
    Route::post("stockusage", [StockUsageController::class, "usage"])->middleware('role:owner,admin');
    Route::get("stockusage", [StockUsageController::class, "getUsage"])->middleware('role:owner,admin');
    Route::get("stockusage/{id}", [StockUsageController::class, "getUsageDetail"])->middleware('role:owner,admin');
    Route::post("stockusage/{id}", [StockUsageController::class, "updateUsage"])->middleware('role:owner,admin');
    // Route::post("stockusage/reject/{id}", [StockUsageController::class, "rejectUsage"])->middleware('role:owner');
    Route::post("approve/stockusage", [StockUsageController::class, "approveUsage"])->middleware('role:owner');
    Route::post("void/stockusage", [StockUsageController::class, "void"])->middleware('role:owner');

    // reports
    Route::get("report/generate", [ReportController::class, "generateReport"]);
    Route::get("report/get", [ReportController::class, "getQueue"]);
    Route::get("report/procurement", [ReportController::class, "procurementReport"]);
    Route::get("report/sales", [ReportController::class, "salesReport"]);
    Route::get("report/stockcard", [ReportController::class, "stockCardReport"]);
    Route::get("report/stockvalue", [ReportController::class, "stockValueReport"]);

    // stockcard
    Route::get("stockcardList", [StockCardController::class, "getStockCardList"]);
    Route::get("stockcardDetails", [StockCardController::class, "getStockCardDetails"]);

    // dashboard
    // Route::get("dashboard/sumary", [DashboardController::class, "monthlySummary"]);
    // Route::get("dashboard/latest-transaction", [DashboardController::class, "getLatestTransaction"]);
    Route::get("dashboard", [DashboardController::class, "dashboard"]);
    Route::get("transaction-sum", [DashboardController::class, "transactionSum"]);
    Route::get("best-seller", [DashboardController::class, "getBestSeller"]);
});

