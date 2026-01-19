<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Str;

try {
    // Mock an order
    $order = new Order();
    $order->company_uuid = Str::uuid()->toString();
    $order->uuid = Str::uuid()->toString();
    $order->public_id = 'order_test_123';

    if (class_exists(OrderReady::class)) {
        echo "OrderReady class exists.\n";
        $event = new OrderReady($order);
        
        if (method_exists($event, 'broadcastOn')) {
            $channels = $event->broadcastOn();
            print_r($channels);
        } else {
            echo "No broadcastOn method.\n";
        }
        
        if (method_exists($event, 'broadcastAs')) {
            echo "Broadcast As: " . $event->broadcastAs() . "\n";
        }
    } else {
        echo "OrderReady class NOT found.\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
