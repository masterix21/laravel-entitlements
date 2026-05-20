<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.plans'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_category_id')
                ->nullable()
                ->constrained(config('entitlements.table_names.plan_categories'))
                ->nullOnDelete();
            $table->text('name');
            $table->string('billing_period');
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
