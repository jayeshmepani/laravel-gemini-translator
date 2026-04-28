<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])

    ->withSkip([
        __DIR__ . '/vendor',
        __DIR__ . '/node_modules',
        __DIR__ . '/storage',
        __DIR__ . '/docs',
        __DIR__ . '/misc',
        __DIR__ . '/coverage',
        __DIR__ . '/build',
        __DIR__ . '/bootstrap/cache',
        __DIR__ . '/tests/Fixtures',
        __DIR__ . '/tests/fixtures',
    ])

    /*
     * Reads PHP target from composer.json.
     * Your composer.json already requires PHP ^8.3.
     */
    ->withPhpSets()

    /*
     * Laravel-aware upgrade rules based on installed composer versions.
     */
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(
        laravel: true,
        phpunit: true,
    )

    /*
     * Package-safe Laravel modernization.
     *
     * Removed:
     * - LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL
     *   because you asked to avoid changes that may affect fast/simple code paths.
     *
     * Kept:
     * - container/class-name/test/type/code-quality improvements.
     */
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])

    /*
     * Gradual levels only.
     * Do not jump to aggressive max levels.
     */
    ->withTypeCoverageLevel(1)
    ->withTypeCoverageDocblockLevel(0)
    ->withDeadCodeLevel(1)
    ->withCodeQualityLevel(1)

    ->withConfiguredRule(RemoveDumpDataDeadCodeRector::class, [
        'dd',
        'dump',
        'var_dump',
    ])

    ->withParallel()

    ->withImportNames(
        importNames: true,
        removeUnusedImports: true,
    );
