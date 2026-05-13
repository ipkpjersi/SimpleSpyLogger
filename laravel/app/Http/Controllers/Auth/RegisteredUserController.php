<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

use function App\Helpers\get_client_ip_address;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        $inviteOnlyRegistration = config('global.invite_only_registration_enabled');

        return view('auth.register', compact('inviteOnlyRegistration'));
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {

        if (! config('global.registrations_enabled')) {
            return redirect()->route('register')->with('error', 'Registrations are currently closed. Please try again later.');
        }

        $ipAddress = get_client_ip_address();

        $recentRegistrations = User::where('registration_ip', $ipAddress)
            ->where('created_at', '>=', now()->subDay())
            ->where('registration_ip', '<>', '127.0.0.1')
            ->count();

        if ($recentRegistrations >= config('global.recent_registrations_limit_daily')) {
            return redirect()->route('register')->with('error', 'Too many registration attempts. Please try again later.');
        }

        $rules = [
            'username' => ['required', 'string', 'max:255', 'unique:users'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];

        $inviteOnlyRegistration = config('global.invite_only_registration_enabled');
        if ($inviteOnlyRegistration) {
            $rules['invite_code'] = 'required|exists:invite_codes,code,used,false';
        }

        $request->validate($rules);

        $user = User::create([
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'registration_ip' => $ipAddress,
        ]);

        if ($inviteOnlyRegistration && $user) {
            $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
            if ($inviteCode) {
                $inviteCode->used = true;
                $inviteCode->username = $request->username;
                $inviteCode->email = $request->email;
                $inviteCode->save();
            }
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect(AppServiceProvider::HOME);
    }
}
