<?php

namespace App\Providers;

use App\Support\SocketCluster\SocketClusterService;
use Fleetbase\Support\SocketCluster\SocketClusterBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class SocketClusterServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Broadcast::extend('socketcluster', function ($app, $config) {
            return new SocketClusterBroadcaster(new SocketClusterService());
        });
    }
}
