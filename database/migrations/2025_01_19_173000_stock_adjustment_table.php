<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        Schema::create('stock_adjustment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_detail_id');
            $table->string('qty');
            $table->timestamp('transaction_date');
            $table->string('reason', 512);
            $table->string('doc_number');
            $table->string('created_by');
            $table->timestamp('created_at')->nullable();
            $table->string('updated_by');
            $table->timestamp('updated_at')->nullable();
            $table->integer('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
