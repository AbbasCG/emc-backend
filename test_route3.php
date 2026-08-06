<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::where('role', 'super_admin')->first();
if (!$user) die("no user");
$request = Illuminate\Http\Request::create('/api/operations/departments/1', 'GET');
$request->setUserResolver(fn() => $user);

$response = $kernel->handle($request);
echo "Status: " . $response->getStatusCode() . "\n";
echo $response->getContent() . "\n";
