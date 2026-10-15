<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'selectors_json')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->longText('selectors_json')->nullable();
            });
        }

        if (! Schema::hasTable('product_price_history')) {
            Schema::create('product_price_history', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('product_id', 36);
                $table->text('name');
                $table->text('price');
                $table->text('image');
                $table->dateTime('captured_at');
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->index(['product_id', 'captured_at'], 'idx_price_history_product_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'selectors_json')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('selectors_json');
            });
        }
    }
};
