<x-app-layout>
    <x-slot name="title">
        {{ config('app.name', 'Laravel') }} - 2FA Verification
    </x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('2Factor Authentication Verification') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <p>Two-factor authentication (2FA) strengthens account security by requiring two methods (also referred to as factors) to verify your identity. Two-factor authentication protects against phishing, social engineering and password brute-force attacks and secures your account from attackers exploiting weak or stolen credentials.</p>
                    <x-auth-validation-errors class="mb-4" :errors="$errors" />
                    @if (session('error'))
                        <div class="mb-4 text-red-600">
                            {{ session('error') }}
                        </div>
                    @endif
                    @if (session('success'))
                        <div class="mb-4 text-green-600">
                            {{ session('success') }}
                        </div>
                    @endif
                    <strong>Enter the passcode from Google Authenticator</strong><br/><br/>
                    <form action="{{ route('2faVerify') }}" method="POST">
                        @csrf
                        <div>
                            <x-input-label for="one_time_password" :value="__('One Time Password')" />
                            <x-text-input id="one_time_password" class="block mt-1 w-full" type="text" name="one_time_password" required autofocus />
                            <x-input-error :messages="$errors->get('one_time_password')" class="mt-2" />
                        </div>
                        <x-primary-button class="mt-4">
                            Authenticate
                        </x-primary-button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
