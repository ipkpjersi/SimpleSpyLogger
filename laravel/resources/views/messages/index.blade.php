<x-app-layout>
    <x-slot name="title">
        {{ config('app.name', 'Laravel') }} - Messages
    </x-slot>

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Messages') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                        All ingested messages across every source. Use the search box for a global filter; click column headers to sort. Pagination is server-side.
                    </p>
                    <table id="messagesTable" class="display w-full">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Source</th>
                                <th>Sent</th>
                                <th>Container</th>
                                <th>Channel</th>
                                <th>Author</th>
                                <th>Visibility</th>
                                <th>Content</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
        $(document).ready(function () {
            const initialSearch = new URLSearchParams(window.location.search).get('search') || '';

            function esc(s) {
                if (s === null || s === undefined || s === '') {
                    return '<span class="text-gray-400 dark:text-gray-500">&mdash;</span>';
                }
                return String(s).replace(/[&<>"']/g, function (ch) {
                    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
                });
            }

            function badge(text, classes) {
                return '<span class="text-white px-2 py-0.5 rounded text-xs font-medium ' + classes + '">' + esc(text) + '</span>';
            }

            const sourceClasses = {
                discord: 'bg-indigo-600',
                twitter: 'bg-sky-500',
                rscplus: 'bg-amber-600',
            };

            const visibilityClasses = {
                public: 'bg-green-600',
                private: 'bg-red-600',
                group: 'bg-yellow-500',
            };

            $('#messagesTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: '{{ route("messages.data") }}',
                order: [[2, 'desc']],
                pageLength: 25,
                lengthMenu: [10, 25, 50, 100],
                search: { search: initialSearch },
                columns: [
                    { data: 'id', name: 'id', width: '60px' },
                    {
                        data: 'source', name: 'source', width: '100px',
                        render: function (d) {
                            return badge(d, sourceClasses[d] || 'bg-gray-500');
                        },
                    },
                    { data: 'sent_at', name: 'sent_at', width: '160px' },
                    { data: 'container_name', name: 'container_name', render: esc },
                    { data: 'channel_name', name: 'channel_name', render: esc },
                    { data: 'author_username', name: 'author_username', render: esc },
                    {
                        data: 'visibility', name: 'visibility', width: '90px',
                        render: function (d) {
                            return badge(d, visibilityClasses[d] || 'bg-gray-500');
                        },
                    },
                    { data: 'content', name: 'content', orderable: false, render: esc },
                ],
            });
        });
    </script>
</x-app-layout>
