<?php

return [
    // Canonical doctor codes plus explicitly configured legacy types. Disabled definitions still fail closed.
    // NURSE is always eligible when any doctor types are configured.
    'doctor_staff_types' => (static function () {
        $types = array_values(array_filter(array_map('trim', explode(',', (string) env('CLINIC_DOCTOR_STAFF_TYPES', '')))));
        if ($types && ! in_array('NURSE', $types, true)) {
            $types[] = 'NURSE';
        }

        return array_values(array_unique([...$types, 'RESIDENT', 'SPECIALIST']));
    })(),
    'export_limit' => 1000,
    'export_doctor_limit' => 5000,
    'export_patient_limit' => 5000,
];
