<x-app-layout>
    <x-slot name="title">
        {{ config('app.name', 'Laravel') }} - 2FA Settings
    </x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('2Factor Authentication Settings') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <p>Two-factor authentication (2FA) strengthens account security by requiring two methods (also referred to as factors) to verify your identity. Two-factor authentication protects against phishing, social engineering and password brute-force attacks and secures your account from attackers exploiting weak or stolen credentials.</p>
                    <br/>
                    <p>To enable Two-Factor Authentication on your account, you need to perform the following actions.</p>
                    <strong>
                        <ol class="list-decimal ml-6">
                            <li>Click on Generate Secret Key. This will generate a unique secret QR code for you to scan.</li>
                            <li>Verify the One-Time Passcode using a 2FA app such as Google Authenticator.</li>
                        </ol>
                    </strong>
                    <br/>

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

                    {{-- If we have never set up 2FA on this account before --}}
                    @if($data['user']->passwordSecurity == null)
                        <form method="POST" action="{{ route('generate2faSecret') }}">
                            @csrf
                            <x-primary-button>
                                Generate Secret Key
                            </x-primary-button>
                        </form>
                    {{-- If we have clicked to enable 2FA/generate 2FA but we have not done so yet --}}
                    @elseif($data['user']->passwordSecurity->google2fa_enable == null)
                        <div class="flex flex-wrap">
                            <div class="w-full md:w-1/2">
                                <strong>Please scan this barcode with your 2FA App:</strong><br/><br>
                                <img src="{{$data['google2fa_url'] }}" alt="">
                            </div>
                            <div class="w-full md:w-1/2">
                                <strong>Now, enter the passcode you are given on the 2FA App.</strong><br/><br/>
                                <form method="POST" action="{{ route('enable2fa') }}">
                                    @csrf
                                    <div>
                                        <x-input-label for="verify-code" :value="__('Authenticator Code')" />
                                        <x-text-input id="verify-code" class="block mt-1 w-full" type="password" name="verify-code" required />
                                        <x-input-error :messages="$errors->get('verify-code')" class="mt-2" />
                                    </div>
                                    <x-primary-button class="mt-4">
                                        Enable 2FA
                                    </x-primary-button>
                                </form>
                            </div>
                        </div>
                        <br/><br/>
                    {{-- If we have 2FA enabled and it is in use --}}
                    @elseif($data['user']->passwordSecurity->google2fa_enable)
                        <p>2FA is currently <strong>enabled</strong> for your account.<br>
                        If you are looking to disable Two-Factor Authentication, please confirm your password below and then click Disable 2FA.</p>
                        <form method="POST" action="{{ route('disable2fa') }}">
                            @csrf
                            <div>
                                <x-input-label for="current-password" :value="__('Current Password')" />
                                <x-text-input id="current-password" class="block mt-1 w-full" type="password" name="current-password" required />
                                <x-input-error :messages="$errors->get('current-password')" class="mt-2" />
                            </div>
                            <x-primary-button class="mt-4">
                                Disable 2FA
                            </x-primary-button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
