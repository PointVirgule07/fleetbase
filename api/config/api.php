<?php

return [
    'cache' => [
        'enabled' => env('API_CACHE_ENABLED', false), // Disabled by default for reliability
        'ttl' => [
            'query' => 300,
            'model' => 3600,
            'relationship' => 1800,
        ],
    ],
    'types' => [
        'order' => [
            [
                'key' => 'default',
                'label' => 'Asset Transport',
                'description' => 'Standard asset transport order',
                'icon' => 'box',
            ],
            // ... more types can be added here
        ]
    ]
];
