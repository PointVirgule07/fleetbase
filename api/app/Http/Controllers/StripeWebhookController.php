<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Str;
use Illuminate\Support\Facades\Http;
use Stripe\Stripe;
use Stripe\Webhook;
use App\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

class StripeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

        if (!$endpoint_secret) {
            Log::error('Stripe webhook secret not set.');
            return response()->json(['error' => 'Configuration error'], 500);
        }

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

        Log::info('Stripe Webhook Received: ' . $event->type);

        switch ($event->type) {
            case 'customer.created':
                $this->handleCustomerCreated($event->data->object);
                break;
            case 'checkout.session.completed':
                $this->handleCheckoutSessionCompleted($event->data->object);
                break;
            default:
                Log::info('Received unhandled event type ' . $event->type);
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleCustomerCreated($stripeCustomer)
    {
        $email = $stripeCustomer->email;
        $name = $stripeCustomer->name;
        $phone = $stripeCustomer->phone;

        if ($email) {
            Log::info("Creating or retrieving contact for email: {$email}");
            
            // Use the specific company UUID for Exotitse
            $companyUuid = 'edd908c2-d261-4b1b-b92e-75a34a71d078';
            
            // Create a new contact if one doesn't exist
            $contact = Contact::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name ?? 'Stripe Customer',
                    'phone' => $phone,
                    'company_uuid' => $companyUuid,
                    'type' => 'customer',
                ]
            );
        }
    }

    protected function handleCheckoutSessionCompleted($session)
    {
        Log::info("Processing checkout session: {$session->id}");

        $customerEmail = $session->customer_details->email ?? null;
        $contact = null;
        $companyUuid = null;

        // Try to find existing user to get the correct company
        if ($customerEmail) {
            $user = User::where('email', $customerEmail)->first();
            if ($user) {
                $companyUuid = $user->company_uuid;
            }
            
            $contact = Contact::where('email', $customerEmail)->first();
            if ($contact && !$companyUuid) {
                $companyUuid = $contact->company_uuid;
            }
        }

        // Fallback to first company if not found
        if (!$companyUuid) {
            // Use the specific company UUID for Exotitse if no user is found
            $companyUuid = 'edd908c2-d261-4b1b-b92e-75a34a71d078';
            
            // Fallback to first company if the specific one doesn't exist (safety check)
            if (!Company::where('uuid', $companyUuid)->exists()) {
                 $company = Company::first();
                 $companyUuid = $company->uuid ?? null;
            }
        }

        if (!$contact) {
            Log::warning("Contact not found for email: {$customerEmail}. Creating one.");
             $contact = Contact::create([
                'email' => $customerEmail,
                'name' => $session->customer_details->name ?? 'Stripe Customer',
                'phone' => $session->customer_details->phone ?? null,
                'company_uuid' => $companyUuid,
                'type' => 'customer',
            ]);
        }

        // Extract shipping details
        $shippingDetails = $session->shipping_details ?? null;
        $addressData = $shippingDetails->address ?? $session->customer_details->address ?? null;
        $name = $shippingDetails->name ?? $session->customer_details->name ?? $contact->name;

        // 1. Create Place (Destination)
        $place = new Place();
        $place->name = $name;
        
        if ($addressData) {
            $place->street1 = $addressData->line1 ?? null;
            $place->street2 = $addressData->line2 ?? null;
            $place->city = $addressData->city ?? null;
            $place->province = $addressData->state ?? null;
            $place->postal_code = $addressData->postal_code ?? null;
            $place->country = $addressData->country ?? null;
        }
        
        $place->location = new Point(0, 0);

        // Geocode address
        if ($addressData) {
            $addressString = implode(', ', array_filter([
                $place->street1,
                $place->city,
                $place->province,
                $place->postal_code,
                $place->country
            ]));

            $apiKey = env('GOOGLE_MAPS_API_KEY');
            
            if ($apiKey && $addressString) {
                // Log key prefix for debugging (first 5 chars)
                Log::info("Attempting geocoding with key starting: " . substr($apiKey, 0, 5) . "...");

                try {
                    $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                        'address' => $addressString,
                        'key' => $apiKey,
                    ]);

                    $data = $response->json();

                    if ($response->successful() && isset($data['status']) && $data['status'] === 'OK') {
                        if (!empty($data['results'])) {
                            $location = $data['results'][0]['geometry']['location'];
                            $lat = $location['lat'];
                            $lng = $location['lng'];
                            $place->location = new Point($lat, $lng);
                            Log::info("Geocoded address: {$addressString} to Lat: {$lat}, Lng: {$lng}");
                        }
                    } else {
                        Log::error("Geocoding failed for address: {$addressString}");
                        Log::error('Google Maps API Error: ' . json_encode($data));
                    }
                } catch (\Exception $e) {
                    Log::error('Geocoding exception: ' . $e->getMessage());
                }
            } else {
                Log::warning('GOOGLE_MAPS_API_KEY is missing in .env or address is empty');
            }
        }

        $place->company_uuid = $companyUuid;
        $place->save();

        // 2. Create Payload
        $payload = new Payload();
        $payload->dropoff_uuid = $place->uuid;
        $payload->company_uuid = $companyUuid;
        $payload->save();

        // 3. Create Order
        $order = new Order();
        $order->customer_uuid = $contact->uuid;
        $order->customer_type = 'Fleetbase\FleetOps\Models\Contact';
        $order->payload_uuid = $payload->uuid; 
        $order->company_uuid = $companyUuid;
        // Set other order details from session
        // $order->amount = $session->amount_total;
        // $order->currency = $session->currency;
        $order->save();
        
        Log::info("Order created: {$order->public_id} for Company: {$companyUuid}");
        
        Log::info("Checkout session processed for contact: {$contact->email}. Address: " . json_encode($addressData));
    }
}
