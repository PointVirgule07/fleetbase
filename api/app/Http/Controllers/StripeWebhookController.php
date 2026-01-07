<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;
use App\Jobs\ProcessStripeWebhook;

class StripeWebhookController extends Controller
{
    /**
     * Handle the Stripe webhook.
     * 
     * Best Practice:
     * 1. Verify Signature
     * 2. Store in Buffer Table (Idempotency)
     * 3. Dispatch Job
     * 4. Return 200 OK
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

        if (!$endpoint_secret) {
            Log::error('Stripe webhook secret not set.');
            return response()->json(['error' => 'Configuration error'], 500);
        }

        // 1. Verify Signature
        try {
            $event = Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
        } catch(\UnexpectedValueException $e) {
            Log::error('Invalid payload: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Invalid signature: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $eventId = $event->id;
        $eventType = $event->type;

        Log::info("Stripe Webhook Received: {$eventType} (ID: {$eventId})");

        // 2. Buffer Event (Idempotency Check)
        // We use firstOrCreate logic manually with DB facade to prevent duplicates
        try {
            $existing = DB::table('stripe_events')->where('event_id', $eventId)->first();
            
            if (!$existing) {
                DB::table('stripe_events')->insert([
                    'event_id' => $eventId,
                    'type' => $eventType,
                    'payload' => $payload, // Store raw payload
                    'status' => 'pending',
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                // 3. Dispatch Job (only if we just inserted it)
                ProcessStripeWebhook::dispatch($eventId)->onQueue('webhooks');
            } else {
                Log::info("Event {$eventId} already exists in buffer. Status: {$existing->status}");
                
                // Optional: Valid retry logic. If it's failed, we might want to re-queue it.
                if ($existing->status === 'failed') {
                    Log::info("Re-queueing failed event {$eventId}");
                    DB::table('stripe_events')
                        ->where('id', $existing->id)
                        ->update(['status' => 'pending', 'updated_at' => now()]);
                    ProcessStripeWebhook::dispatch($eventId)->onQueue('webhooks');
                }
            }
            
        } catch (\Illuminate\Database\QueryException $e) {
            // Check for duplicate key error (1062) just in case a race condition happened between SELECT and INSERT
            if (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                Log::info("Event {$eventId} was inserted concurrently (Race Condition). Skipping.");
            } else {
                Log::error("Database error buffering Stripe event: " . $e->getMessage());
                return response()->json(['error' => 'Database error'], 500);
            }
        }

        // 4. Return 200 OK immediately
        return response()->json(['status' => 'buffered']);
    }
}
