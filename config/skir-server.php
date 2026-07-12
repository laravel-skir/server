<?php

declare(strict_types=1);

return [
    'manifests' => [
        base_path('app/Skir/skirout/skir-server-manifest.json'),
    ],

    'generator_command' => ['npx', 'skir', 'gen'],

    'scaffolding' => [
        'controller_style' => 'module',
        'controller_namespace' => 'App\\Skir',
        'single_controller' => 'App\\Skir\\SkirController',
        'request_namespace' => 'App\\Http\\Requests\\Skir',
        'form_requests' => true,
    ],
];
