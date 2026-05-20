<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.licenses'), function (Blueprint $table): void {
            $table->id();
            $table->morphs('subscriber');
            $table->foreignId('plan_id')
                ->constrained(config('entitlements.table_names.plans'));
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained(config('entitlements.table_names.licenses'))
                ->nullOnDelete();
            $table->string('type');
            $table->unsignedInteger('slot_total');
            $table->unsignedInteger('slot_used')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['subscriber_type', 'subscriber_id', 'type']);
            $table->index(['starts_at', 'ends_at']);
        });
    }
};
