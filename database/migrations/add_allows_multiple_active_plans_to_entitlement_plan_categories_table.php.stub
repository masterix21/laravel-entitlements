<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('entitlements.table_names.plan_categories'), function (Blueprint $table): void {
            $table->boolean('allows_multiple_active_plans')->default(true)->after('name');
        });
    }
};
