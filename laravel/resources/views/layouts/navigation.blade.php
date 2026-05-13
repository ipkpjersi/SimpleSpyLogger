<nav x-data="{ open: false, showDropdown: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ Auth::user() != null ? route('home') : route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                    </a>
                    <a href="{{ Auth::user() != null ? route('home') : route('dashboard') }}" class="ml-3 text-xl no-underline text-gray-800 dark:text-gray-200 hover:text-gray-800 dark:hover:text-gray-200">
                        <span>{{ config('app.name', 'Laravel') }}</span>
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                    @if (Auth::user() != null)
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-nav-link>
                    @else
                        <x-nav-link :href="route('home')" :active="request()->routeIs('home')">
                            {{ __('Home') }}
                        </x-nav-link>
                    @endif
                    <div class="hidden sm:flex sm:items-center sm:ml-6">
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger" class="hover:border-gray-300 dark:hover:border-gray-700 focus:outline-none focus:text-gray-700 dark:focus:text-gray-300 focus:border-gray-300 dark:focus:border-gray-700">
                                <button class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition duration-150 ease-in-out">
                                    Dropdown
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-600">
                                    {{ __('Dropdown Search') }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-600">
                                    {{ __('Dropdown Categories') }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-600">
                                    {{ __('Top Dropdown') }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>
                    <x-nav-link href="#" :active="false">
                        {{ __('Other') }}
                    </x-nav-link>
                </div>
            </div>

            <!-- Global Searching -->
            <div class="hidden items-center space-x-4 xl:flex">
                <input id="globalSearch" type="text" class="dark:bg-gray-800 dark:text-gray-100 rounded-md p-2" placeholder="Search...">
                <select id="searchType" class="rounded-md p-2 dark:bg-gray-800 dark:text-gray-100 no_dropdown_arrow">
                    <option value="option">Option</option>
                    <option value="other">Other</option>
                </select>
                <button id="searchButton" class="p-2 bg-blue-500 hover:bg-blue-700 text-white rounded-md">Search</button>
            </div>

            <!-- Settings Dropdown -->
            @if (Auth::user() != null)
                <div class="hidden sm:flex sm:items-center sm:ml-6">
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                                <div class="flex items-center">
                                    <img onerror="this.onerror=null; this.src='/img/notfound.gif';" class="rounded-lg shadow-md h-8 w-8 mr-2" src="{{ Auth::user()->avatar }}" alt="{{ Auth::user()->username ?? "" }}" />
                                    <div>{{ Auth::user()->username ?? "" }}</div>
                                </div>

                                <div class="ml-1">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <x-dropdown-link :href="route('profile.edit')">
                                {{ __('Profile Settings') }}
                            </x-dropdown-link>
                            @if(Auth::user()->isAdmin())
                                <x-dropdown-link :href="route('invite-codes-index')">
                                    {{ __('Invite Codes') }}
                                </x-dropdown-link>
                            @endif

                            <!-- Authentication -->
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')"
                                        onclick="event.preventDefault();
                                                    this.closest('form').submit();">
                                    {{ __('Log Out') }}
                                </x-dropdown-link>
                            </form>
                        </x-slot>
                    </x-dropdown>
                </div>
            @else
                <div class="hidden absolute sm:flex sm:items-center" style="right: 1rem; top: 1.25rem;">
                    <a href="{{ route('login') }}" class="font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:outline focus:outline-2 focus:rounded-sm focus:outline-red-500">
                        Login
                    </a>
                    <a href="{{ route('register') }}" class="ml-4 font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white focus:outline focus:outline-2 focus:rounded-sm focus:outline-red-500">
                        Register
                    </a>
                </div>
            @endif

            <!-- Hamburger -->
            <div class="-mr-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-900 focus:text-gray-500 dark:focus:text-gray-400 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            @if (Auth::user() != null)
                <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    {{ __('Dashboard') }}
                </x-responsive-nav-link>
            @else
                <x-responsive-nav-link :href="route('home')" :active="request()->routeIs('home')">
                    {{ __('Home') }}
                </x-responsive-nav-link>
            @endif
            <x-responsive-nav-link @click="showDropdown = !showDropdown" href="#" :active="false">
                {{ __('Dropdown') }}
            </x-responsive-nav-link>
            <div x-show="showDropdown" class="ml-4 space-y-1">
                <x-responsive-nav-link href="#">
                    {{ __('Dropdown Search') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link href="#">
                    {{ __('Dropdown Categories') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link href="#">
                    {{ __('Top Dropdown') }}
                </x-responsive-nav-link>
            </div>
            <x-responsive-nav-link href="#" :active="false">
                {{ __('Other') }}
            </x-responsive-nav-link>
            @if (Auth::user() == null)
                <div class="border-t border-gray-200 dark:border-gray-700"></div>
                <x-responsive-nav-link :href="route('login')">
                    {{ __('Login') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('register')">
                    {{ __('Register') }}
                </x-responsive-nav-link>
            @endif
        </div>

        @if (Auth::user() != null)
            <!-- Responsive Settings Options -->
            <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
                <div class="flex items-center px-4">
                    <div class="ml-3">
                        <div class="flex items-center">
                            <img onerror="this.onerror=null; this.src='/img/notfound.gif';" class="rounded-lg shadow-md h-8 w-8 mr-2" src="{{ Auth::user()->avatar }}" alt="{{ Auth::user()->username }}" />
                            <div class="font-medium text-base text-gray-800 dark:text-gray-200">{{ Auth::user()->username }}</div>
                        </div>
                    </div>
                </div>

                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('profile.edit')">
                        {{ __('Profile Settings') }}
                    </x-responsive-nav-link>
                    @if(Auth::user()->isAdmin())
                        <x-responsive-nav-link :href="route('invite-codes-index')">
                            {{ __('Invite Codes') }}
                        </x-responsive-nav-link>
                    @endif

                    <!-- Authentication -->
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <x-responsive-nav-link :href="route('logout')"
                                onclick="event.preventDefault();
                                            this.closest('form').submit();">
                            {{ __('Log Out') }}
                        </x-responsive-nav-link>
                    </form>
                </div>
            </div>
        @endif
    </div>
</nav>
