<?php

namespace App\Console\Commands;

use App\Facades\eScriptorium;
use Illuminate\Console\Command;

class EscriptoriumWebsocketTestCommand extends Command
{
    protected $signature = 'escriptorium:ws-test
                            {--document= : Document ID to join room for}
                            {--timeout=30 : Timeout in seconds}';

    protected $description = 'Test WebSocket connection to eScriptorium';

    public function handle(): int
    {
        $this->components->info('Testing eScriptorium WebSocket connection...');
        $this->newLine();

        $timeout = (int) $this->option('timeout');

        // Show the WebSocket URL for debugging
        $wsUrl = eScriptorium::getWebSocketUrl();
        $this->components->twoColumnDetail('<fg=gray>WebSocket URL</>', $wsUrl);
        $this->newLine();

        try {
            // ============================================================
            // STEP 1: Connect to WebSocket (includes authentication)
            // ============================================================
            $this->components->task('Connecting to WebSocket (with authentication)', function () use ($timeout) {
                $this->client = eScriptorium::createWebSocketClient($timeout);

                return true;
            });

            $this->components->info('✅ WebSocket connected successfully!');
            $this->newLine();

            // ============================================================
            // STEP 2: Join document room if specified
            // ============================================================
            $documentId = $this->option('document');
            if ($documentId) {
                $this->components->task("Joining document room (ID: {$documentId})", function () use ($documentId) {
                    $this->client->text(json_encode([
                        'type' => 'join-room',
                        'object_cls' => 'document',
                        'object_pk' => (int) $documentId,
                    ]));

                    return true;
                });

                $this->components->info('📡 Joined document room');
                $this->newLine();

                // Wait for messages
                $this->components->info('Listening for messages (Ctrl+C to stop)...');
                $this->newLine();

                $startTime = time();

                while ((time() - $startTime) < $timeout) {
                    try {
                        $message = $this->client->receive();
                        $data = json_decode($message->getContent(), true);

                        $this->line('<fg=cyan>['.date('H:i:s').']</> '.json_encode($data, JSON_PRETTY_PRINT));
                        $this->newLine();

                    } catch (\Exception $e) {
                        if (str_contains($e->getMessage(), 'timeout') || str_contains(strtolower($e->getMessage()), 'timed out')) {
                            $this->line('<fg=gray>['.date('H:i:s').'] (waiting...)</>');

                            continue;
                        }
                        throw $e;
                    }
                }
            }

            $this->client->close();
            $this->components->info('Connection closed');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->components->error('WebSocket connection failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private ?\WebSocket\Client $client = null;
}
