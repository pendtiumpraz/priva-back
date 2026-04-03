<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$orgs = \App\Models\Organization::count();
$users = \App\Models\User::count();
$gaps = \App\Models\GapAssessment::count();
$vendors = \App\Models\Vendor::count();
$ropas = \DB::table('ropas')->count();
$dpias = \DB::table('dpias')->count();

echo "📊 Data Summary:\n";
echo "  Orgs: $orgs | Users: $users\n";
echo "  GAPs: $gaps | Vendors: $vendors | ROPAs: $ropas | DPIAs: $dpias\n\n";

echo "🏢 Pertamina Orgs:\n";
$pertOrgs = \App\Models\Organization::where('name', 'like', '%Pertamina%')->orWhere('name', 'like', '%Kilang%')->orWhere('name', 'like', '%Elnusa%')->get();
foreach ($pertOrgs as $o) {
    $userCount = \App\Models\User::where('org_id', $o->id)->count();
    echo "  [{$o->org_level}] {$o->name} — Users: {$userCount}\n";
}
