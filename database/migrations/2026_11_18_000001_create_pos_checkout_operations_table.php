<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pos_checkout_operations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('user_id');
            $table->uuid('operation_id');
            $table->char('payload_hash', 64);
            $table->unsignedInteger('transaction_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'operation_id'], 'pos_checkout_operation_unique');
            $table->index(['business_id', 'transaction_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_checkout_operations');
    }
};
