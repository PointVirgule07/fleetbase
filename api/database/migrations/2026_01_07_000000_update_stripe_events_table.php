<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            if (!Schema::hasColumn('stripe_events', 'attempts')) {
                $table->integer('attempts')->default(0);
            }
            if (!Schema::hasColumn('stripe_events', 'last_error')) {
                $table->text('last_error')->nullable();
            }
            if (!Schema::hasColumn('stripe_events', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $columnToDrop = [];
            if (Schema::hasColumn('stripe_events', 'attempts')) {
                $columnToDrop[] = 'attempts';
            }
             if (Schema::hasColumn('stripe_events', 'last_error')) {
                $columnToDrop[] = 'last_error';
            }
             if (Schema::hasColumn('stripe_events', 'processed_at')) {
                $columnToDrop[] = 'processed_at';
            }
            $table->dropColumn($columnToDrop);
        });
    }
};
