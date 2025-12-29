<?php

namespace App\Http\Controllers;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController as BaseDriverController;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\Company;
use Fleetbase\Support\Auth;
use Illuminate\Support\Str;

class DriverAuthController extends BaseDriverController
{
    public function loginWithPhone()
    {
        $phone = static::phone();

        // check if user exists
        $user = User::where('phone', $phone)->whereHas('driver')->whereNull('deleted_at')->first();
        if (!$user) {
            return response()->apiError('No driver with this phone # found.');
        }

        // Get the user's company for this driver profile
        $company = static::getDriverCompanyFromUser($user);

        // SEND VERIFICATION CODE BY EMAIL IF DRIVER HAS EMAIL ADDRESS
        if ($user->email) {
            try {
                VerificationCode::generateEmailVerificationFor($user, 'driver_login', [
                    'company_uuid'    => $company->uuid,
                    'messageCallback' => function ($verification) use ($company) {
                        return 'Your ' . data_get($company, 'name', config('app.name')) . ' verification code is ' . $verification->code;
                    },
                ]);

                return response()->json(['status' => 'OK', 'method' => 'email']);
            } catch (\Throwable $e) {
                if (app()->bound('sentry')) {
                    app('sentry')->captureException($e);
                }
                return response()->apiError('Unable to send Email Verification code: ' . $e->getMessage());
            }
        }

        return response()->apiError('Driver has no email address.');
    }

    private static function getDriverCompanyFromUser(User $user): ?Company
    {
        // company defaults to null
        $company = null;

        // Load the driver profile to get the company
        $driverProfiles = Driver::where('user_uuid', $user->uuid)->get();
        if ($driverProfiles->count() > 0) {
            // Get the driver profile matching user current company session
            $currentDriverProfile = $driverProfiles->first(function ($driverProfile) use ($user) {
                return $user->company_uuid === $driverProfile->company_uuid;
            });
            $driverProfile = $currentDriverProfile ? $currentDriverProfile : $driverProfiles->first();
            // get company from driver profile
            $company = Company::where('uuid', $driverProfile->company_uuid)->first();
        }

        // If unable to find company from driver profile, fallback to session flow
        if (!$company) {
            $company = Auth::getCompanySessionForUser($user);
        }

        return $company;
    }

    private static function phone(?string $phone = null): string
    {
        if ($phone === null) {
            $phone = request()->input('phone');
        }

        // Remove spaces and non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (!Str::startsWith($phone, '+')) {
            $phone = '+' . $phone;
        }

        \Illuminate\Support\Facades\Log::info('Driver login attempt', ['input' => request()->input('phone'), 'formatted' => $phone]);

        return $phone;
    }
}
