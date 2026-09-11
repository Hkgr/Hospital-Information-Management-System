<?php

return [
    // Explicit stable staff_types.code values; an empty configuration fails closed.
    'doctor_staff_types' => array_values(array_filter(array_map('trim', explode(',', (string) env('CLINIC_DOCTOR_STAFF_TYPES', ''))))),
    'export_limit' => 1000,
    'export_doctor_limit' => 5000,
];
