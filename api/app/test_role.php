<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Fleetbase\Models\Company;
use Fleetbase\Models\User;

$company = Company::first();
$user = User::where('email', 'test@example.com')->first();
if (!$user) {
    $user = User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => 'password', 'phone' => '+1234567890']);
}
$user->setUserType('user');

echo "Assigning company with empty string role...\n";
try {
    $user->assignCompany($company, '');
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

$user->load('companyUser.roles');
echo "User Company UUID: " . $user->company_uuid . "\n";
echo "User Type: " . $user->type . "\n";
if ($user->companyUser) {
    echo "Roles: " . $user->companyUser->roles->pluck('name') . "\n";
} else {
    echo "No company user\n";
}

