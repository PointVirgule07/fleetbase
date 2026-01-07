<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->json('payload')->nullable(); // Store the event payload
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('stripe_events');
    }
};
