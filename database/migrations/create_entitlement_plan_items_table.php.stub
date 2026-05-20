<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.plan_items'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')
                ->constrained(config('entitlements.table_names.plans'))
                ->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('quantity');
            $table->boolean('is_flexible')->default(false);
            $table->timestamps();
        });
    }
};
