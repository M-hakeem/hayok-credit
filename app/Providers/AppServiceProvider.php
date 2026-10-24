<?php

namespace App\Providers;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
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

            foreach ($openApi->paths as $path) {
                foreach ($path->operations as $operation) {
                    if ($operation->operationId === 'businessLoanApplication.store' && $operation->requestBodyObject) {
                        $documentTypes = [
                            'account_owner_identity_document',
                            'account_owner_address_proof',
                            'account_owner_passport_photograph',
                            'certificate_of_incorporation',
                            'memorandum_and_articles',
                            'directors_status_report',
                            'business_address_proof',
                            'tin_certificate',
                            'board_resolution',
                            'financial_statements',
                            'bank_statements',
                        ];

                        foreach ($operation->requestBodyObject->content as $requestSchema) {
                            $requestSchema = $requestSchema instanceof Reference
                                ? $requestSchema->resolve()
                                : $requestSchema;

                            if (! $requestSchema instanceof Schema || ! $requestSchema->type instanceof ObjectType) {
                                continue;
                            }

                            $source = $requestSchema->type;
                            $flattened = new ObjectType;
                            $flattened->setDescription(
                                'For multipart/form-data, use the bracketed field names shown below. Swagger UI provides one stakeholder and all 11 required documents; additional stakeholders can be added with the same indexed field format.'
                            );

                            $flatten = function (string $name, Type $type, bool $required) use (&$flatten, $flattened, $documentTypes): void {
                                if ($type instanceof ObjectType) {
                                    foreach ($type->properties as $propertyName => $propertyType) {
                                        if ($propertyType instanceof Type) {
                                            $flatten(
                                                "{$name}[{$propertyName}]",
                                                $propertyType,
                                                $required && in_array($propertyName, $type->required, true),
                                            );
                                        }
                                    }

                                    return;
                                }

                                if ($type instanceof ArrayType) {
                                    $itemType = $type->items instanceof Schema
                                        ? $type->items->type
                                        : $type->items;

                                    if ($itemType instanceof ObjectType) {
                                        $itemCount = $name === 'documents' ? count($documentTypes) : 1;

                                        for ($index = 0; $index < $itemCount; $index++) {
                                            foreach ($itemType->properties as $propertyName => $propertyType) {
                                                if (! $propertyType instanceof Type) {
                                                    continue;
                                                }

                                                $fieldName = "{$name}[{$index}][{$propertyName}]";
                                                $fieldRequired = $required && in_array($propertyName, $itemType->required, true);
                                                if ($name === 'documents' && $propertyName === 'type') {
                                                    $propertyType = (new StringType)
                                                        ->enum([$documentTypes[$index]])
                                                        ->setDescription("Required document type: {$documentTypes[$index]}.");
                                                    $fieldRequired = $required;
                                                }

                                                $flatten($fieldName, $propertyType, $fieldRequired);
                                            }
                                        }

                                        return;
                                    }

                                    if ($itemType instanceof Type) {
                                        $flatten("{$name}[0]", $itemType, $required);
                                    }

                                    return;
                                }

                                $flattened->addProperty($name, $type);
                                if ($required) {
                                    $flattened->addRequired([$name]);
                                }
                            };

                            foreach ($source->properties as $propertyName => $propertyType) {
                                if (! $propertyType instanceof Type) {
                                    continue;
                                }

                                if ($propertyName === 'consent') {
                                    $propertyType = (new StringType)
                                        ->enum(['true', '1', 'yes', 'on'])
                                        ->setDescription('Confirm consent by selecting true, 1, yes, or on.');
                                }

                                $flatten($propertyName, $propertyType, in_array($propertyName, $source->required, true));
                            }

                            $flattened->addRequired(['consent']);
                            $requestSchema->type = $flattened;
                        }

                        continue;
                    }

                    if ($operation->operationId !== 'partnerLoanApplication.store' || ! $operation->requestBodyObject) {
                        continue;
                    }

                    foreach ($operation->requestBodyObject->content as $requestSchema) {
                        $requestSchema = $requestSchema instanceof Reference
                            ? $requestSchema->resolve()
                            : $requestSchema;

                        if (! $requestSchema instanceof Schema || ! $requestSchema->type instanceof ObjectType) {
                            continue;
                        }

                        $guarantors = $requestSchema->type->getProperty('guarantors') ?? null;
                        if (! $guarantors instanceof ArrayType) {
                            continue;
                        }

                        $itemType = $guarantors->items instanceof Schema
                            ? $guarantors->items->type
                            : $guarantors->items;
                        if (! $itemType instanceof ObjectType) {
                            continue;
                        }

                        if ($itemType->hasProperty('id_file')) {
                            $idFile = $itemType->getProperty('id_file');
                            $idFileType = $idFile instanceof Schema ? $idFile->type : $idFile;
                            if ($idFileType instanceof Type) {
                                $idFileType->format('binary')->contentMediaType('application/octet-stream');
                                $idFileType->setDescription(
                                    'Multipart file upload (PDF, JPG, JPEG, or PNG); max 5MB.'
                                );
                            }
                        } else {
                            $itemType->addProperty(
                                'id_file',
                                (new StringType)->setDescription(
                                    'Multipart file upload (PDF, JPG, JPEG, or PNG); max 5MB.'
                                )->format('binary')->contentMediaType('application/octet-stream')
                            );
                        }

                        $itemType->addRequired(['id_file']);
                    }
                }
            }
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
