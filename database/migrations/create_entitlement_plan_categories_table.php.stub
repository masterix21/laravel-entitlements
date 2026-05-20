<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.plan_categories'), function (Blueprint $table): void {
            $table->id();
            $table->text('name');
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }
};
