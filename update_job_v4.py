import os

file_path = '/opt/fleetbase/api/app/Jobs/ProcessStripeWebhook.php'
content = r"""<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessStripeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $eventData;
    public $eventType;
    public $eventId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($eventData, $eventType, $eventId)
    {
        $this->eventData = $eventData;
        $this->eventType = $eventType;
        $this->eventId = $eventId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("Processing Stripe Event Job: {$this->eventId} Type: {$this->eventType}");

        try {
            // Update status to processing
            DB::table('stripe_events')->where('event_id', $this->eventId)->update(['status' => 'processing']);

            switch ($this->eventType) {
                case 'customer.created':
                    $this->handleCustomerCreated($this->eventData);
                    break;
                case 'checkout.session.completed':
                    $this->handleCheckoutSessionCompleted($this->eventData);
                    break;
                default:
                    Log::info('Received unhandled event type in job ' . $this->eventType);
            }

            // Update status to completed
            DB::table('stripe_events')->where('event_id', $this->eventId)->update(['status' => 'completed']);

        } catch (\Exception $e) {
            Log::error("Job failed for event {$this->eventId}: " . $e->getMessage());
            DB::table('stripe_events')->where('event_id', $this->eventId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            
            // Re-throw to let Laravel Queue handle retries if configured
            throw $e;
        }
    }

    protected function getTargetCompanyUuid()
    {
        $targetId = env('STRIPE_TARGET_COMPANY_UUID');

        if (!$targetId) {
            return null;
        }

        // Check if it's a UUID
        if (Str::isUuid($targetId)) {
            return $targetId;
        }

        // Check if it's a public_id (starts with company_)
        if (Str::startsWith($targetId, 'company_')) {
            $company = Company::where('public_id', $targetId)->first();
            if ($company) {
                return $company->uuid;
            }
        }

        Log::warning("Could not resolve STRIPE_TARGET_COMPANY_UUID: {$targetId}");
        return null;
    }

    protected function handleCustomerCreated($stripeCustomer)
    {
        // Convert array to object if needed (Stripe SDK returns objects, but passing to Job might serialize to array)
        $stripeCustomer = (object) $stripeCustomer;
        
        $email = $stripeCustomer->email ?? null;
        $name = $stripeCustomer->name ?? null;
        $phone = $stripeCustomer->phone ?? null;

        if ($email) {
            Log::info("Creating or retrieving contact for email: {$email}");
            
            $companyUuid = $this->getTargetCompanyUuid();
            
            if (!$companyUuid) {
                Log::error('STRIPE_TARGET_COMPANY_UUID is not set or invalid.');
                return;
            }
            
            Contact::firstOrCreate(
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
        $session = (object) $session;
        // Handle nested objects which might be arrays after serialization
        $customerDetails = isset($session->customer_details) ? (object) $session->customer_details : null;
        $shippingDetails = isset($session->shipping_details) ? (object) $session->shipping_details : null;

        Log::info("Processing checkout session logic for: {$session->id}");

        $customerEmail = $customerDetails->email ?? null;
        $contact = null;
        $companyUuid = null;
        $targetCompanyUuid = $this->getTargetCompanyUuid();

        if (!$targetCompanyUuid) {
            Log::error('STRIPE_TARGET_COMPANY_UUID is not set or invalid.');
        }

        if ($customerEmail) {
            // 1. Try to find user in the specific target company first
            if ($targetCompanyUuid) {
                $user = User::where('email', $customerEmail)->where('company_uuid', $targetCompanyUuid)->first();
                if ($user) {
                    $companyUuid = $user->company_uuid;
                }
            }
            
            if (!$companyUuid) {
                // 2. Fallback to any user
                $user = User::where('email', $customerEmail)->first();
                if ($user) {
                    $companyUuid = $user->company_uuid;
                }
            }
            
            // 3. Try to find contact in the specific target company
            if (!$companyUuid && $targetCompanyUuid) {
                 $contact = Contact::where('email', $customerEmail)->where('company_uuid', $targetCompanyUuid)->first();
                 if ($contact) {
                     $companyUuid = $contact->company_uuid;
                 }
            }

            // 4. Fallback to any contact
            if (!$companyUuid) {
                $contact = Contact::where('email', $customerEmail)->first();
                if ($contact) {
                    $companyUuid = $contact->company_uuid;
                }
            }
        }

        // Default to the target company if nothing found
        if (!$companyUuid) {
            $companyUuid = $targetCompanyUuid;
            if ($companyUuid && !Company::where('uuid', $companyUuid)->exists()) {
                 $company = Company::first();
                 $companyUuid = $company->uuid ?? null;
            }
        }

        if (!$contact && $companyUuid) {
             $contact = Contact::create([
                'email' => $customerEmail,
                'name' => $customerDetails->name ?? 'Stripe Customer',
                'phone' => $customerDetails->phone ?? null,
                'company_uuid' => $companyUuid,
                'type' => 'customer',
            ]);
        }

        if (!$companyUuid) {
            Log::error("Could not determine company UUID for Stripe event {$this->eventId}");
            return;
        }

        $addressData = null;
        if ($shippingDetails && isset($shippingDetails->address)) {
            $addressData = (object) $shippingDetails->address;
        } elseif ($customerDetails && isset($customerDetails->address)) {
            $addressData = (object) $customerDetails->address;
        }
        
        $name = $shippingDetails->name ?? $customerDetails->name ?? $contact->name;

        // 1. Create Place
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

            // Use server-specific key if available
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

        $place->company_uuid = $companyUuid;
        $place->save();

        // 2. Create Payload
        $payload = new Payload();
        $payload->dropoff_uuid = $place->uuid;
        $payload->company_uuid = $companyUuid;
        $payload->type = 'default'; 
        $payload->save();

        // 3. Create Order
        $order = new Order();
        $order->customer_uuid = $contact->uuid;
        $order->customer_type = 'Fleetbase\FleetOps\Models\Contact';
        $order->payload_uuid = $payload->uuid; 
        $order->company_uuid = $companyUuid;
        $order->type = 'default'; // Set a default type
        $order->status = 'created';
        $order->save();
        
        Log::info("Order created: {$order->public_id} for Company: {$companyUuid}");
    }
}
"""

try:
    with open(file_path, 'w') as f:
        f.write(content)
    print("File updated successfully.")
except Exception as e:
    print(f"Failed to update file: {e}")
