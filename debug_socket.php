<?php

require '/fleetbase/api/vendor/autoload.php';

use Fleetbase\Support\SocketCluster\SocketClusterService;
use WebSocket\Client;

echo "Debug script starting...\n";

// Mimic the config pulling logic or just hardcode what we saw in docker-compose.override.yml
$options = [
    'host' => getenv('SOCKETCLUSTER_HOST') ?: 'socket',
    'port' => getenv('SOCKETCLUSTER_PORT') ?: 8000,
    'path' => getenv('SOCKETCLUSTER_PATH') ?: '/socketcluster/',
    'secure' => getenv('SOCKETCLUSTER_SECURE') === 'true',
];

echo "Options: " . print_r($options, true) . "\n";

// Manually parse URI like the class does
$scheme = ($options['secure']) ? 'wss' : 'ws';
$host = trim($options['host'], '/');
$port = !empty($options['port']) ? ':' . $options['port'] : '';
$path = trim($options['path'], '/');
// WARNING: The class code did: $path = !empty($path) ? $path . '/' : '';
// Let's verify exactly what the class does.
$pathStr = $path;
$pathStr = !empty($pathStr) ? $pathStr . '/' : '';

$uri = sprintf('%s://%s%s/%s', $scheme, $host, $port, $pathStr);

echo "Constructed URI: " . $uri . "\n";

try {
    echo "Attempting connection...\n";
    $client = new Client($uri, array_merge($options, ['timeout' => 5])); 
    echo "Connection object created.\n";
    
    // SKIP PING, GO STRAIGHT TO HANDSHAKE
    echo "Attempting handshake...\n";
    // Mimic the class behavior: empty array
    $handshake = json_encode(['event' => '#handshake', 'data' => [], 'cid' => 1]);
    $client->send($handshake);
    echo "Handshake sent. Receiving...\n";
    $response = $client->receive();
    echo "Handshake Response: " . $response . "\n";

    echo "Attempting publish...\n";
    $largePayload = str_repeat('x', 1000); // 1KB
    $publish = json_encode([
        'event' => '#publish',
        'data' => [
            'channel' => 'company.0f084bb9-4099-498a-b955-bd27ded2ba9a',
            'data' => ['foo' => 'bar', 'payload' => $largePayload]
        ],
        'cid' => 2
    ]);
    $client->send($publish);
    echo "Publish sent. Receiving...\n";
    $pubResponse = $client->receive();
    echo "Publish Response: " . $pubResponse . "\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
