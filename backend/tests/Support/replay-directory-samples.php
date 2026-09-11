<?php

use App\Services\Directory\DirectoryReport;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Synthetic report replay requires testing.');
}
// Replays the exact captured server documents. No database reads or writes.
$root = base_path('docs/samples/comparison');
$documents = json_decode(file_get_contents($root.'/documents.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($documents as $name => $document) {
    if (! preg_match('#^(doctors/doctor|clinics/clinic)-(list|compact|short|single|detail)\.(pdf|xlsx)$#', $name, $match)) {
        throw new RuntimeException('Unexpected sample path.');
    }
    $path = $root.'/after/'.$name;
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, app(DirectoryReport::class)->response($document, $match[3])->getContent());
    echo $name."\n";
}
