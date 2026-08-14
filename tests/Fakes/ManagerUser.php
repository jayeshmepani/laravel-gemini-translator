<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Fakes;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class ManagerUser extends Authenticatable
{
    protected $guarded = [];
}
