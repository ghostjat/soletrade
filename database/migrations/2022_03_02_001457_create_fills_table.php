<?php

use Illuminate\Database\Migrations\Migration;
use App\Illuminate\Database\Schema\Blueprint;
use App\Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('trade_id');
            $table->bigInteger('timestamp');
            $table->decimal('size');
            $table->decimal('price');
            $table->decimal('commission');
            $table->string('commission_asset');
            $table->timestamps();
            $table->index(['order_id', 'trade_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('fills');
    }
};