<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\FuncCall\RenameFunctionRector;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\PropertyFetch\RenamePropertyRector;
use Rector\Renaming\ValueObject\MethodCallRename;
use Rector\Renaming\ValueObject\RenameProperty;
use RectorLaravel\Rector\Class_\UnifyModelDatesWithCastsRector;

# see https://laravel.com/docs/10.x/upgrade
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(__DIR__ . '/../config.php');

    // https://github.com/laravel/framework/pull/32856/files
    $rectorConfig->rule(UnifyModelDatesWithCastsRector::class);

    $rectorConfig
        ->ruleWithConfiguration(RenamePropertyRector::class, [
            # https://github.com/laravel/laravel/commit/edcbe6de7c3f17070bf0ccaa2e2b785158ae5ceb
            new RenameProperty('App\Http\Kernel', 'routeMiddleware', 'middlewareAliases'),
        ]);

    $rectorConfig
        ->ruleWithConfiguration(RenameMethodRector::class, [
            // https://github.com/laravel/framework/pull/41136/files
            new MethodCallRename('Illuminate\Database\Eloquent\Relations\Relation', 'getBaseQuery', 'toBase'),
            // https://github.com/laravel/framework/pull/42591/files
            new MethodCallRename('Illuminate\Support\Facades\Bus', 'dispatchNow', 'dispatchSync'),
        ]);

    $rectorConfig
        ->ruleWithConfiguration(RenameFunctionRector::class, [
            // https://github.com/laravel/framework/pull/42591/files
            'dispatch_now' => 'dispatch_sync',
        ]);
};
