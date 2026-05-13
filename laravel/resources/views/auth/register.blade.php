<x-guest-layout>
    <x-slot name="title">
        {{ config('app.name', 'Laravel') }} - Register
    </x-slot>
    <div class="flex justify-center flex-col items-center mb-6">
        <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
        <h2 class="text-xl text-gray-700 dark:text-gray-300">{{ config('app.name', 'Laravel') }}</h2>
    </div>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Username -->
        <div>
            <x-input-label for="username" :value="__('Username')" />
            <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="email" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        @if ($inviteOnlyRegistration)
            <!-- Invite Code -->
            <div class="mt-4">
                <x-input-label for="invite_code" :value="__('Invite Code')" />
                <x-text-input id="invite_code" class="block mt-1 w-full" type="text" name="invite_code" :value="old('invite_code')" required autofocus autocomplete="invite_code" />
                <x-input-error :messages="$errors->get('invite_code')" class="mt-2" />
            </div>
        @endif

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ml-4">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
    <!-- Custom Error Message -->
    @if (session('error'))
        <div class="mt-4 mb-4 no-dark text-red-600 max-w-[250px] overflow-x-auto">
            {{ session('error') }}
        </div>
    @endif
</x-guest-layout>
