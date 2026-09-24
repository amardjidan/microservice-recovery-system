<?php

return [
    'default_incident_entity' => [
        'source' => [
            'connection' => 'order_sim',
            'table' => 'orders',
            'key_column' => 'order_ref',
            'amount_column' => 'amount',
            'time_column' => 'created_at',
        ],
        'target' => [
            'connection' => 'payment_sim',
            'table' => 'payments',
            'key_column' => 'order_ref',
            'amount_column' => 'amount',
        ],
    ],
];