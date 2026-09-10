<?php

use App\Http\Middleware\EnsureApiDocsEnabled;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    // Wildcard preserves /api in operation paths, with the server at the app root.
    'api_path' => 'api/*',
    'enabled' => env('API_DOCS_ENABLED'),
    'info' => [
        'version' => '1.0.0',
        'description' => 'API for the Emirati Hospital information management system.',
    ],
    'ui' => ['title' => 'Hospital Information Management System API'],
    'dev_tools' => ['enabled' => false],
    // No session middleware or cookies are needed by the documentation UI.
    'middleware' => [EnsureApiDocsEnabled::class, RestrictedDocsAccess::class],
    'renderers' => ['elements' => ['tryItCredentialsPolicy' => 'omit']],
];
