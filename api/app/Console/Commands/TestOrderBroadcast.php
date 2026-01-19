<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Events\OrderReady;

class TestOrderBroadcast extends Command
{
    protected $signature = 'test:broadcast-order {uuid?}';
    protected $description = 'Manually broadcast an OrderReady event to test socket connection';

    public function handle()
    {
        $uuid = $this->argument('uuid');

        if ($uuid) {
            $order = Order::where('uuid', $uuid)->first();
        } else {
            $order = Order::latest()->first();
        }

        if (!$order) {
            $this->error("No order found.");
            return;
        }

        $this->info("Broadcasting OrderReady for: {$order->public_id} (UUID: {$order->uuid})");
        $this->info("Company UUID: {$order->company_uuid}");

        try {
            // Force verify session state before broadcast if needed
            // session(['company' => $order->company_uuid]);

            event(new OrderReady($order));
            $this->info("Event dispatched!");
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
