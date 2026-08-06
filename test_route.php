<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$user = App\Models\User::where('role', 'super_admin')->first();
if (!$user) { echo "No super admin found\n"; exit; }

// Test /api/operations/departments/1
$request = Illuminate\Http\Request::create('/api/operations/departments/1', 'GET');
$request->setUserResolver(fn() => $user);

$response = $kernel->handle($request);
echo "STATUS: " . $response->getStatusCode() . "\n";
echo $response->getContent() . "\n";
