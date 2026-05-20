<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Concerns\HasEntitlements;

final class Subscriber extends Model
{
    use HasEntitlements;

    protected $guarded = [];
}
