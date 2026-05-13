<?php

namespace App\Http\Controllers;

use App\Models\PasswordSecurity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PasswordSecurityController extends Controller
{
    public function show2faForm(Request $request): View
    {
        $user = Auth::user();

        $google2fa_url = '';
        if ($user->passwordSecurity()->exists()) {
            $google2fa = app('pragmarx.google2fa');
            $google2fa_url = $google2fa->getQRCodeInline(
                env('APP_NAME', 'laravel'),
                $user->email,
                $user->passwordSecurity->google2fa_secret
            );
        }
        $data = [
            'user' => $user,
            'google2fa_url' => $google2fa_url,
        ];

        return view('2fa')->with('data', $data);
    }

    public function generate2faSecret(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $google2fa = app('pragmarx.google2fa');

        $passwordSecurity = new PasswordSecurity;
        $passwordSecurity->user_id = $user->id;
        $passwordSecurity->google2fa_enable = 0;
        $passwordSecurity->google2fa_secret = $google2fa->generateSecretKey();
        $passwordSecurity->save();

        return redirect('/2fa')->with('success', 'Your secret key has been generated. Please verify the code to enable 2FA.');
    }

    public function enable2fa(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $userForSaving = User::find(Auth::user()->id);
        $google2fa = app('pragmarx.google2fa');
        $secret = $request->input('verify-code');
        $valid = $google2fa->verifyKey($user->passwordSecurity->google2fa_secret, $secret);
        if ($valid) {
            $user->passwordSecurity->google2fa_enable = 1;
            $userForSaving->google2fa_enabled = 1;
            $user->passwordSecurity->save();
            $userForSaving->save();

            return redirect('2fa')->with('success', 'Your 2FA has been enabled successfully.');
        } else {
            return redirect('2fa')->with('error', 'Invalid Verification Code. Please try again.');
        }
    }

    public function disable2fa(Request $request): RedirectResponse
    {
        if (! (Hash::check($request->input('current-password'), Auth::user()->password))) {
            return redirect()->back()->with('error', 'The password you entered is not correct. Please try again or contact a member of staff for further support.');
        }

        $validatedData = $request->validate([
            'current-password' => 'required',
        ]);
        $user = Auth::user();
        $user->google2fa_enabled = 0;
        $user->passwordSecurity->delete();
        $user->save();

        return redirect('/2fa')->with('success', 'Your 2FA has been disabled successfully.');
    }
}
