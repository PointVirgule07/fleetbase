<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\Support\ApiModelCache;
use Fleetbase\FleetOps\Events\OrderReady;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

class ProcessStripeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $eventId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($eventId)
    {
        $this->eventId = $eventId;
        $this->onQueue('webhooks');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $eventId = $this->eventId;
        Log::info("Starting Async Job for Stripe Event ID: {$eventId}");

        $shouldProcess = false;
        $eventRecord = null;

        try {
            DB::transaction(function () use ($eventId, &$shouldProcess, &$eventRecord) {
                $eventRecord = DB::table('stripe_events')
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if (!$eventRecord) {
                    Log::error("Stripe Event ID {$eventId} not found in buffer.");
                    return;
                }

                if ($eventRecord->status === 'done' || $eventRecord->status === 'processing') {
                    Log::info("Stripe Event ID {$eventId} is already {$eventRecord->status}. Skipping.");
                    return;
                }

                DB::table('stripe_events')
                    ->where('id', $eventRecord->id)
                    ->update(['status' => 'processing', 'updated_at' => now()]);

                $shouldProcess = true;
            });

            if (!$shouldProcess || !$eventRecord) {
                return;
            }

            $payload = json_decode($eventRecord->payload);
            $type = $eventRecord->type;

            Log::info("Processing Stripe Event: {$eventId} Type: {$type} - Status locked to processing");

            switch ($type) {
                case 'checkout.session.completed':
                    if (isset($payload->data->object)) {
                        $this->handleCheckoutSessionCompleted($payload->data->object);
                    } else {
                        Log::warning("Payload data object missing for {$eventId}");
                    }
                    break;
                default:
                    Log::info("Handling generic or unhandled event type: {$type}");
                    break;
            }

            // Mark done
            DB::table('stripe_events')
                ->where('event_id', $eventId)
                ->update([
                    'status' => 'done',
                    'processed_at' => now(),
                    'updated_at' => now()
                ]);

            Log::info("Successfully processed Stripe Event ID: {$eventId}");

        } catch (\Exception $e) {
            Log::error("Job failed for event {$this->eventId}: " . $e->getMessage());
            
            $maxAttempts = env('STRIPE_MAX_ATTEMPTS', 5);
            
            $currentRecord = DB::table('stripe_events')->where('event_id', $eventId)->first();
            $currentAttempts = $currentRecord ? $currentRecord->attempts : 0;
            $newAttempts = $currentAttempts + 1;
            
            $status = 'pending';
            if ($newAttempts >= $maxAttempts) {
                $status = 'failed';
            }
            
            DB::table('stripe_events')->where('event_id', $eventId)->update([
                'status' => $status,
                'attempts' => $newAttempts,
                'last_error' => $e->getMessage(),
                'updated_at' => now()
            ]);
            
            if ($status === 'pending') {
                $this->release(30); 
            }
        }
    }

    protected function getTargetCompany()
    {
        $identifier = env('STRIPE_TARGET_COMPANY_ID');
        if (!$identifier) {
             throw new \Exception("STRIPE_TARGET_COMPANY_ID is not configured");
        }

        if (Str::isUuid($identifier)) {
            $company = Company::where('uuid', $identifier)->first();
        } elseif (Str::startsWith($identifier, 'company_')) {
            $company = Company::where('public_id', $identifier)->first();
        } elseif (is_numeric($identifier)) {
            $company = Company::where('id', (int) $identifier)->first();
        } else {
            $company = Company::where('id', $identifier)
                ->orWhere('uuid', $identifier)
                ->orWhere('public_id', $identifier)
                ->first();
        }

        if (!$company) {
             throw new \Exception("Company with identifier {$identifier} not found");
        }

        return $company;
    }

    protected function handleCheckoutSessionCompleted($session)
    {
        $session = (object) $session;
        $sessionId = $session->id;

        // Get hardcoded company
        $company = $this->getTargetCompany();
        $companyUuid = $company->uuid;
        $companyPublicId = $company->public_id ?? null;

        Log::info("Processing checkout session logic for: {$sessionId} under Company: {$companyUuid}");

        // Check for duplicate order using meta
        if (Order::where('meta->stripe_session_id', $sessionId)->exists()) {
            Log::info("Order for Stripe Session {$sessionId} already exists. Skipping creation.");
            return;
        }

        $customerDetails = isset($session->customer_details) ? (object) $session->customer_details : null;
        $shippingDetails = isset($session->shipping_details) ? (object) $session->shipping_details : null;
        
        $customerEmail = $customerDetails->email ?? null;
        $customerName = $customerDetails->name ?? 'Stripe Customer';
        $customerPhone = $customerDetails->phone ?? null;

        // Find or Create Customer in the target company
        $contact = Contact::where('email', $customerEmail)->where('company_uuid', $companyUuid)->first();
        if (!$contact) {
            $contact = Contact::create([
                'company_uuid' => $companyUuid,
                'name' => $customerName,
                'email' => $customerEmail,
                'phone' => $customerPhone,
                'type' => 'customer',
            ]);
        }

        // Create Dropoff Place (Customer Address)
        $addressData = null;
        if ($shippingDetails && isset($shippingDetails->address)) {
            $addressData = (object) $shippingDetails->address;
        } elseif ($customerDetails && isset($customerDetails->address)) {
            $addressData = (object) $customerDetails->address;
        }

        $dropoffPlace = new Place();
        $dropoffPlace->company_uuid = $companyUuid;
        $dropoffPlace->name = $customerName;
        $dropoffPlace->location = new Point(0, 0); // Default

        if ($addressData) {
            $dropoffPlace->street1 = $addressData->line1 ?? null;
            $dropoffPlace->street2 = $addressData->line2 ?? null;
            $dropoffPlace->city = $addressData->city ?? null;
            $dropoffPlace->province = $addressData->state ?? null;
            $dropoffPlace->postal_code = $addressData->postal_code ?? null;
            $dropoffPlace->country = $addressData->country ?? null;
            
            // Geocode
            $this->geocodePlace($dropoffPlace);
        }
        $dropoffPlace->save();

        // Find or Create Pickup Place
        // Logic: Find a place named "Store" or "Warehouse" or take the first place created by the company.
        $pickupPlace = Place::where('company_uuid', $companyUuid)
            ->where(function($query) {
                $query->where('name', 'like', '%Store%')
                      ->orWhere('name', 'like', '%Warehouse%');
            })->first();

        if (!$pickupPlace) {
            $pickupPlace = Place::where('company_uuid', $companyUuid)->orderBy('created_at', 'asc')->first();
        }

        if (!$pickupPlace) {
            // Create a default pickup place if none exists
            $pickupPlace = Place::create([
                'company_uuid' => $companyUuid,
                'name' => 'Default Store',
                'street1' => '123 Main St', // Placeholder
                'location' => new Point(0, 0)
            ]);
        }

        // Create Payload
        $payload = new Payload();
        $payload->company_uuid = $companyUuid;
        $payload->pickup_uuid = $pickupPlace->uuid;
        $payload->dropoff_uuid = $dropoffPlace->uuid;
        $payload->type = 'default';
        $payload->save();

        // Create Entities (Items)
        Entity::create([
            'company_uuid' => $companyUuid,
            'payload_uuid' => $payload->uuid,
            'name' => 'Stripe Order Item',
            'description' => 'Order from Stripe Checkout',
            'type' => 'item',
            'length' => 0,
            'width' => 0,
            'height' => 0,
            'weight' => 0,
        ]);

        // Create Order
        $order = new Order();
        $order->company_uuid = $companyUuid;
        $order->customer_uuid = $contact->uuid;
        $order->customer_type = 'Fleetbase\FleetOps\Models\Contact';
        $order->payload_uuid = $payload->uuid;
        $order->type = 'default';
        $order->status = 'created';
        $order->meta = ['stripe_session_id' => $sessionId];
        $order->save();

        // Create Waypoints
        // 1. Pickup
        Waypoint::create([
            'company_uuid' => $companyUuid,
            'order_uuid' => $order->uuid,
            'place_uuid' => $pickupPlace->uuid,
            'order' => 1,
        ]);

        // 2. Dropoff
        Waypoint::create([
            'company_uuid' => $companyUuid,
            'order_uuid' => $order->uuid,
            'place_uuid' => $dropoffPlace->uuid,
            'order' => 2,
        ]);

        // Manually invalidate API cache to ensure the order is visible on the dashboard immediately
        try {
            ApiModelCache::invalidateModelCache($order, $companyUuid);

            if ($companyPublicId && $companyPublicId !== $companyUuid) {
                ApiModelCache::invalidateModelCache($order, $companyPublicId);
            }
        } catch (\Exception $e) {
            Log::warning("Cache invalidation failed: " . $e->getMessage());
        }

        // Broadcast OrderReady event
        try {
            event(new OrderReady($order));
            Log::info("Dispatched OrderReady event for {$order->public_id}");
        } catch (\Exception $e) {
            Log::warning('OrderReady broadcast failed: ' . $e->getMessage());
        }

        Log::info("Order created successfully: {$order->public_id} for Company: {$companyUuid}");
    }

    protected function geocodePlace(Place $place)
    {
        $addressString = implode(', ', array_filter([
            $place->street1,
            $place->city,
            $place->province,
            $place->postal_code,
            $place->country
        ]));

        $apiKey = env('GOOGLE_MAPS_SERVER_API_KEY', env('GOOGLE_MAPS_API_KEY'));
        
        if ($apiKey && $addressString) {
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
                }
            } catch (\Exception $e) {
                Log::error('Geocoding exception: ' . $e->getMessage());
            }
        }
    }
}
