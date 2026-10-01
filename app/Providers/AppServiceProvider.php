<?php

namespace App\Providers;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Max 3 OTP sends per phone per 10 minutes — prevents SMS flooding/billing abuse
        RateLimiter::for('send-otp', function (Request $request) {
            $phone = $request->input('phone', $request->input('phone_number', 'unknown'));

            return [
                Limit::perMinutes(10, 3)->by('otp-phone:'.hash('sha256', (string) $phone)),
                Limit::perMinutes(10, 10)->by('otp-send-ip:'.$request->ip()),
            ];
        });

        // Max 5 verify attempts per phone per 10 minutes — prevents OTP brute force
        RateLimiter::for('verify-otp', function (Request $request) {
            $phone = $request->input('phone', $request->input('phone_number', 'unknown'));

            return [
                Limit::perMinutes(10, 5)->by('otp-verify-phone:'.hash('sha256', (string) $phone)),
                Limit::perMinutes(10, 30)->by('otp-verify-ip:'.$request->ip()),
            ];
        });

        // Max 3 password reset sends per phone per 10 minutes; also cap by IP.
        RateLimiter::for('forgot-password', function (Request $request) {
            $phone = $request->input('phone_number', 'unknown');

            return [
                Limit::perMinutes(10, 3)->by('forgot-phone:'.hash('sha256', (string) $phone)),
                Limit::perMinutes(10, 10)->by('forgot-ip:'.$request->ip()),
            ];
        });

        // Max 5 login attempts per IP per minute — prevents credential stuffing
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'status' => 'error',
                    'message' => 'Too many login attempts. Please try again later.',
                ], 429));
        });

        Scramble::afterOpenApiGenerated(function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi) {
            $openApi->components->addSecurityScheme(
                'bearerAuth',
                SecurityScheme::http('bearer', 'JWT')
                    ->as('bearerAuth')
                    ->setDescription('Use the Bearer token from /api/auth/login')
            );

            $openApi->components->addSecurityScheme(
                'partnerKey',
                SecurityScheme::apiKey('header', 'X-Partner-Key')
                    ->as('partnerKey')
                    ->setDescription('Partner API key — set PARTNER_API_KEY in .env')
            );
        });

        Scramble::configure()->withOperationTransformers(function (OperationTransformers $transformers) {
            $transformers->append(function (Operation $operation, RouteInfo $routeInfo) {
                $middleware = collect($routeInfo->route->gatherMiddleware());

                if ($middleware->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth:') || $m === 'auth'))) {
                    $operation->addSecurity(new SecurityRequirement(['bearerAuth' => []]));
                }

                if ($middleware->contains('partner')) {
                    $operation->addSecurity(new SecurityRequirement(['partnerKey' => []]));
                }
            });
        });
    }
}
