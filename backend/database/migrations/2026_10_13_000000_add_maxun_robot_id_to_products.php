<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'maxun_robot_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('maxun_robot_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'maxun_robot_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('maxun_robot_id');
            });
        }
    }
};
