<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.license_usages'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('license_id')
                ->constrained(config('entitlements.table_names.licenses'))
                ->cascadeOnDelete();
            $table->morphs('subject');
            $table->unsignedInteger('amount');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['license_id', 'status']);
        });
    }
};
