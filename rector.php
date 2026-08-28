<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\PHPUnit\CodeQuality\Rector\StmtsAwareInterface\DeclareStrictTypesTestsRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Transform\Rector\String_\StringToClassConstantRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Function_\AddFunctionVoidReturnTypeWhereNoReturnRector;
use Rector\ValueObject\PhpVersion;
use RectorLaravel\Rector\Class_\RemoveModelPropertyFromFactoriesRector;
use RectorLaravel\Rector\Empty_\EmptyToBlankAndFilledFuncRector;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;
use RectorLaravel\Rector\MethodCall\ResponseHelperCallToJsonResponseRector;
use RectorLaravel\Rector\MethodCall\WhereToWhereLikeRector;
use RectorLaravel\Rector\StaticCall\RouteActionCallableRector;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withPhpSets(
        php83: true,
    )
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        AddClosureVoidReturnTypeWhereNoReturnRector::class,
        AddFunctionVoidReturnTypeWhereNoReturnRector::class,

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

        ReadOnlyPropertyRector::class => [
            __DIR__ . '/app/Models/*',
            __DIR__ . '/Modules/*/Models/*',
            __DIR__ . '/Modules/*/Entities/*',
        ],

        DeclareStrictTypesTestsRector::class => [
            __DIR__ . '/tests/*',
        ],

        StringToClassConstantRector::class => [
            __DIR__ . '/app/Http/Controllers/Auth/LoginController.php',
        ],
    ])
    ->withComposerBased(
        phpunit: true,
    )
    ->withTypeCoverageLevel(1)
    ->withTypeCoverageDocblockLevel(0)
    ->withSetProviders(LaravelSetProvider::class)
    ->withDeadCodeLevel(5)
    ->withCodeQualityLevel(31)
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_TESTING,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        LaravelLevelSetList::UP_TO_LARAVEL_110,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withRules([
        ClassPropertyAssignToConstructorPromotionRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
        EmptyToBlankAndFilledFuncRector::class,
        ResponseHelperCallToJsonResponseRector::class,
        RemoveModelPropertyFromFactoriesRector::class,
    ])
    ->withConfiguredRule(RemoveDumpDataDeadCodeRector::class, [
        'dd',
        'dump',
        'var_dump',
        'ray',
        'ds',
        'ddd',
        'rd',
    ])
    ->withConfiguredRule(RouteActionCallableRector::class, [
        'NAMESPACE' => 'App\Http\Controllers',
    ])
    ->withConfiguredRule(WhereToWhereLikeRector::class, [
        'usingPostgresDriver' => false,
    ])
    ->withParallel()
    ->withImportNames(
        importNames: true,
        removeUnusedImports: true,
    );
