<?php

return [
    'attachment_max_kb' => (int) env('DOSSIER_ATTACHMENT_MAX_KB', 10240),
    'attachment_disk' => 'dossier_private',
    'export_limit' => 1000,
    'report_detail_limit' => 5000,
];
