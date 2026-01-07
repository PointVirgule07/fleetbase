<?php

namespace App\Support\SocketCluster;

use Fleetbase\Support\SocketCluster\SocketClusterMessage;
use Fleetbase\Support\SocketCluster\SocketClusterService as BaseSocketClusterService;
use Illuminate\Support\Facades\Log;

class SocketClusterService extends BaseSocketClusterService
{
    /**
     * Send a message to the given channel with additional resilience and logging.
     */
    public function send($channel, array $data = []): bool
    {
        $cid        = rand();
        $message    = new SocketClusterMessage($channel, $data, $cid);
        $this->sent = false;

        try {
            $this->handshake($cid);

            if (!$this->handshakeSent) {
                $this->error = $this->handshakeError ?? 'SocketCluster handshake failed.';
                Log::warning('SocketCluster handshake failed', [
                    'channel' => $channel,
                    'uri'     => $this->uri ?? null,
                    'error'   => $this->error,
                ]);

                return false;
            }

            $this->client->send($message);
            $this->response = $this->client->receive();
            $this->sent     = true;
        } catch (\WebSocket\ConnectionException $exception) {
            $this->error = $exception->getMessage();
        } catch (\WebSocket\TimeoutException $exception) {
            $this->error = $exception->getMessage();
        } catch (\Throwable $exception) {
            $this->error = $exception->getMessage();
        } finally {
            $this->closeClient();
        }

        if (!$this->sent && $this->error) {
            Log::warning('SocketCluster publish failed', [
                'channel'          => $channel,
                'uri'              => $this->uri ?? null,
                'error'            => $this->error,
                'handshake_error'  => $this->handshakeError ?? null,
            ]);
        }

        return $this->sent;
    }

    /**
     * Safely close the underlying WebSocket client.
     */
    protected function closeClient(): void
    {
        if (!isset($this->client)) {
            return;
        }

        try {
            $this->client->close();
        } catch (\Throwable $exception) {
            Log::debug('SocketCluster client close error', ['error' => $exception->getMessage()]);
        }
    }
}
