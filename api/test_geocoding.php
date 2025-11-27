<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Fleetbase\FleetOps\Support\Geocoding;
use Illuminate\Support\Facades\Log;

$address = '11 RUE JOUFFROY, SAINT-ÉTIENNE, 42000, FRANCE';

echo "Testing Geocoding for address: $address\n";
echo "API Key: " . env('GOOGLE_MAPS_API_KEY') . "\n";

try {
    $results = Geocoding::geocode($address);
    
    if ($results->isEmpty()) {
        echo "No results found.\n";
    } else {
        echo "Results found:\n";
        foreach ($results as $place) {
            echo "Location: " . $place->location->getLat() . ", " . $place->location->getLng() . "\n";
            echo "Formatted Address: " . $place->address . "\n";
        }
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
