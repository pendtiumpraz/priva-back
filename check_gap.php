<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$all = \App\Models\GapAssessment::withTrashed()->orderBy('created_at','desc')->get();
foreach ($all as $a) {
    echo "ID: {$a->id} | org: {$a->org_id} | reg: " . ($a->regulation_code ?? 'NULL') . " | ver: {$a->version} | score: {$a->overall_score} | created: {$a->created_at}\n";
}
echo "Total: " . $all->count() . "\n";
