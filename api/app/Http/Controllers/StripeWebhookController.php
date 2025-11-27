<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Support\Geocoding;
use Fleetbase\Models\Company;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Str;

class StripeWebhookController extends Controller
{
    /**
     * Handle the Stripe webhook.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

        // DEBUG: Write to a custom log file to ensure we see it
        $logMsg = "Stripe Webhook Hit at " . date('Y-m-d H:i:s') . "\n";
        $logMsg .= "Signature: " . $sig_header . "\n";
        $logMsg .= "Secret (env): " . $endpoint_secret . "\n";
        file_put_contents(storage_path('logs/stripe_debug.log'), $logMsg, FILE_APPEND);

        if (!$endpoint_secret) {
            Log::error('Stripe Webhook Error: STRIPE_WEBHOOK_SECRET is not set in .env');
            file_put_contents(storage_path('logs/stripe_debug.log'), "Error: Secret not set\n", FILE_APPEND);
            return response()->json(['error' => 'Configuration error'], 500);
        }

        try {
            $event = Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
        } catch(\UnexpectedValueException $e) {
            // Invalid payload
            Log::error('Stripe Webhook Error: Invalid payload. ' . $e->getMessage());
            file_put_contents(storage_path('logs/stripe_debug.log'), "Error: Invalid payload - " . $e->getMessage() . "\n", FILE_APPEND);
            return response()->json(['error' => 'Invalid payload: ' . $e->getMessage()], 400);
        } catch(SignatureVerificationException $e) {
            // Invalid signature
            Log::error('Stripe Webhook Error: Invalid signature. ' . $e->getMessage());
            file_put_contents(storage_path('logs/stripe_debug.log'), "Error: Invalid signature - " . $e->getMessage() . "\n", FILE_APPEND);
            
            return response()->json(['error' => 'Invalid signature: ' . $e->getMessage()], 400);
        }

        // Handle the event
        if ($event->type == 'checkout.session.completed') {
            $session = $event->data->object;
            $this->handleCheckoutSessionCompleted($session);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle the checkout.session.completed event.
     *
     * @param  object  $session
     * @return void
     */
    protected function handleCheckoutSessionCompleted($session)
    {
        Log::info('Stripe Checkout Session Completed: ' . $session->id);

        // Extract customer details
        $customerDetails = $session->customer_details ?? null;
        $shippingDetails = $session->shipping_details ?? null;

        // Determine address (prefer shipping, fallback to customer)
        $addressData = $shippingDetails->address ?? $customerDetails->address ?? null;
        $name = $shippingDetails->name ?? $customerDetails->name ?? 'Unknown Customer';
        $email = $customerDetails->email ?? null;
        $phone = $customerDetails->phone ?? null;

        if (!$addressData) {
            Log::warning('No address found in Stripe session');
            return;
        }

        // Find a company to associate with (required for Fleetbase models)
        // In a single-tenant setup, we can just take the first one.
        // Or we could look for a company UUID in the session metadata.
        $companyUuid = $session->metadata->company_uuid ?? null;
        
        if ($companyUuid) {
            $company = Company::where('uuid', $companyUuid)->first();
        } else {
            $company = Company::first();
        }

        if (!$company) {
            Log::error('No company found to associate with Stripe Order');
            return;
        }

        // Find or create customer
        $contact = null;
        if ($email || $phone) {
            $contact = Contact::where('company_uuid', $company->uuid)
                ->where(function($query) use ($email, $phone) {
                    if ($email) {
                        $query->orWhere('email', $email);
                    }
                    if ($phone) {
                        $query->orWhere('phone', $phone);
                    }
                })
                ->first();
        }

        if (!$contact) {
            $contact = Contact::create([
                'company_uuid' => $company->uuid,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'type' => 'customer',
            ]);
            Log::info("Created new Customer {$contact->uuid} from Stripe Session");
        } else {
            Log::info("Found existing Customer {$contact->uuid} for Stripe Session");
        }

        // Attempt to geocode the address
        $location = new Point(0, 0);
        $addressString = sprintf(
            '%s %s, %s, %s, %s',
            $addressData->line1,
            $addressData->line2 ?? '',
            $addressData->city,
            $addressData->state,
            $addressData->country
        );

        try {
            // Check if Google Maps API key is set before trying
            if (config('services.google_maps.api_key') || env('GOOGLE_MAPS_API_KEY')) {
                $results = Geocoding::geocode($addressString);
                if ($results->isNotEmpty()) {
                    $first = $results->first();
                    if ($first->location) {
                        $location = $first->location;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Geocoding failed for Stripe Order: ' . $e->getMessage());
        }

        // Create Place (Dropoff Location)
        $place = Place::create([
            'company_uuid' => $company->uuid,
            'name' => $addressData->line1,
            'street1' => $addressData->line1,
            'street2' => $addressData->line2,
            'city' => $addressData->city,
            'postal_code' => $addressData->postal_code,
            'province' => $addressData->state,
            'country' => $addressData->country,
            'email' => $email,
            'phone' => $phone,
            'location' => $location,
            'latitude' => $location->getLat(),
            'longitude' => $location->getLng(),
        ]);

        Log::info("Created Place {$place->uuid} from Stripe Session");

        // Create Payload
        // We create a payload with the dropoff location set to the place we just created.
        $payload = Payload::create([
            'company_uuid' => $company->uuid,
            'dropoff_uuid' => $place->uuid,
            'type' => 'delivery', // Default type
            'meta' => [
                'source' => 'stripe_webhook',
            ]
        ]);

        // Get default order config
        $orderConfig = OrderConfig::default($company);

        // Create Order
        $order = Order::create([
            'company_uuid' => $company->uuid,
            'customer_uuid' => $contact->uuid,
            'customer_type' => 'Fleetbase\FleetOps\Models\Contact',
            'order_config_uuid' => $orderConfig ? $orderConfig->uuid : null,
            'payload_uuid' => $payload->uuid,
            'external_id' => $session->id,
            'status' => 'created',
            'type' => $orderConfig ? $orderConfig->key : 'default',
            'meta' => [
                'stripe_session_id' => $session->id,
                'stripe_payment_intent' => $session->payment_intent,
                'stripe_amount_total' => $session->amount_total,
                'stripe_currency' => $session->currency,
            ]
        ]);

        Log::info("Created Order {$order->uuid} for Place {$place->uuid} and Customer {$contact->uuid}");
        // Debug log to verify customer assignment
        file_put_contents(storage_path('logs/stripe_debug.log'), "Created Order {$order->uuid} with Customer UUID: {$contact->uuid}\n", FILE_APPEND);
    }
}
