<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\PlanCategoryResource;

final class ListPlanCategories extends ListRecords
{
    protected static string $resource = PlanCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
