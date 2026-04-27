<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets(php83: true)
    ->withTypeCoverageLevel(1)
    ->withDeadCodeLevel(1)
    ->withCodeQualityLevel(1)
    ->withParallel()
    ->withImportNames(false, true);
