<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateItemsDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('items_details', function (Blueprint $table) {
            $table->id();
            $table->string('item_code');
            $table->unsignedBigInteger('location_id');
            $table->integer('qty');
            $table->decimal('price',10,2);
            $table->string('created_by');
            $table->timestamp('created_at')->nullable();
            $table->string('updated_by');
            $table->timestamp('updated_at')->nullable();
            $table->integer('status');

            $table->foreign('item_code')->references('item_code')->on('items');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('items_details');
    }
}
