<?php

return [
    // Lowest priority wins; key breaks ties. Only implemented dashboards belong here.
    'general' => [
        'title' => 'لوحة التحكم',
        'priority' => 100,
        'access' => 'facility_permissions',
        'permissions' => ['dashboards.view'],
    ],
];
