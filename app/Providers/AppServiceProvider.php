<?php

namespace App\Providers;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::afterOpenApiGenerated(function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi) {
            $openApi->components->addSecurityScheme(
                'bearerAuth',
                SecurityScheme::http('bearer', 'JWT')
                    ->as('bearerAuth')
                    ->setDescription('Use the Bearer token from /api/auth/login')
            );
        });

        Scramble::configure()->withOperationTransformers(function (OperationTransformers $transformers) {
            $transformers->append(function (Operation $operation, RouteInfo $routeInfo) {
                if (collect($routeInfo->route->gatherMiddleware())->contains(fn ($middleware) => is_string($middleware) && (str_starts_with($middleware, 'auth:') || $middleware === 'auth'))) {
                    $operation->addSecurity(new SecurityRequirement(['bearerAuth' => []]));
                }
            });
        });
    }
}
