<?php

use Opcodes\LogViewer\Enums\SortingMethod;
use Opcodes\LogViewer\Enums\SortingOrder;
use Opcodes\LogViewer\Enums\Theme;
use Opcodes\LogViewer\Http\Middleware\AuthorizeLogViewer;

return [

    'enabled' => env('LOG_VIEWER_ENABLED', true),

    'api_only' => env('LOG_VIEWER_API_ONLY', false),

    'require_auth_in_production' => true,

    'route_domain' => null,

    'route_path' => env('LOG_VIEWER_PATH', 'log-viewer'),

    'assets_path' => 'vendor/log-viewer',

    'back_to_system_url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/admin',

    'back_to_system_label' => 'Admin',

    'timezone' => null,

    'datetime_format' => 'Y-m-d H:i:s',

    'middleware' => [
        'web',
        AuthorizeLogViewer::class,
    ],

    /*
    | The Log Viewer UI calls these routes from the browser with the same
    | session cookie as /admin. EnsureFrontendRequestsAreStateful only
    | starts that session when the referer host is listed as stateful, so an
    | admin on any other host gets 403 "This action is unauthorized."
    | The web group always restores the session for these first-party calls.
    */
    'api_middleware' => [
        'web',
        AuthorizeLogViewer::class,
    ],

    'api_stateful_domains' => env('LOG_VIEWER_API_STATEFUL_DOMAINS') ? explode(',', (string) env('LOG_VIEWER_API_STATEFUL_DOMAINS')) : null,

    'hosts' => [
        'local' => [
            'name' => ucfirst((string) env('APP_ENV', 'local')),
        ],
    ],

    'include_files' => [
        '*.log',
        '**/*.log',
    ],

    'exclude_files' => [
        //
    ],

    'hide_unknown_files' => true,

    'shorter_stack_trace_excludes' => [
        '/vendor/symfony/',
        '/vendor/laravel/framework/',
        '/vendor/barryvdh/laravel-debugbar/',
    ],

    'cache_driver' => env('LOG_VIEWER_CACHE_STORE', env('LOG_VIEWER_CACHE_DRIVER')),

    'cache_key_prefix' => 'lv',

    'lazy_scan_chunk_size_in_mb' => 50,

    'strip_extracted_context' => true,

    'per_page_options' => [10, 25, 50, 100, 250, 500],

    'defaults' => [
        'use_local_storage' => true,
        'folder_sorting_method' => SortingMethod::ModifiedTime,
        'folder_sorting_order' => SortingOrder::Descending,
        'file_sorting_method' => SortingMethod::ModifiedTime,
        'log_sorting_order' => SortingOrder::Descending,
        'per_page' => 25,
        'theme' => Theme::System,
        'shorter_stack_traces' => false,
    ],

    'exclude_ip_from_identifiers' => env('LOG_VIEWER_EXCLUDE_IP_FROM_IDENTIFIERS', false),

    'root_folder_prefix' => 'root',

];
