<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.plan_transitions'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('anchor_license_id')
                ->constrained(config('entitlements.table_names.licenses'))
                ->cascadeOnDelete();
            $table->foreignId('target_plan_id')
                ->constrained(config('entitlements.table_names.plans'));
            $table->json('quantity_overrides')->nullable();
            $table->string('apply_mode');
            $table->string('status');
            $table->timestamp('scheduled_at');
            $table->timestamp('applied_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('new_anchor_license_id')
                ->nullable()
                ->constrained(config('entitlements.table_names.licenses'))
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('anchor_license_id');
        });
    }
};
