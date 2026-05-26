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
        <div class="max-w-full mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-x-auto shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                        All ingested messages across every source. Use the search box for a global filter; click column headers to sort. Pagination is server-side. Hover a message to preview it, or click it to open the full content.
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="messageModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-3xl w-full max-h-[80vh] flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-800 dark:text-gray-200">Message content</h3>
                <button id="messageModalClose" type="button" class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 text-2xl leading-none">&times;</button>
            </div>
            <div id="messageModalBody" class="p-4 overflow-auto whitespace-pre-wrap break-words text-sm text-gray-900 dark:text-gray-100"></div>
        </div>
    </div>

    <script type="module">
        import '/js/jquery.dataTables.yadcf.js';

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

            function renderContent(data, type) {
                if (type !== 'display') {
                    return data;
                }
                if (data === null || data === undefined || data === '') {
                    return '<span class="text-gray-400 dark:text-gray-500">&mdash;</span>';
                }
                const full = String(data);
                const truncated = full.length > 150 ? full.slice(0, 150) + '...' : full;
                // full is non-empty here, so esc() escapes it for the title attribute too.
                return '<span class="ssl-content cursor-pointer hover:underline" style="display:inline-block;max-width:32rem;vertical-align:top;white-space:normal;word-break:break-word;overflow-wrap:anywhere;" title="' + esc(full) + '">' + esc(truncated) + '</span>';
            }

            const modal = document.getElementById('messageModal');
            const modalBody = document.getElementById('messageModalBody');

            function showModal(content) {
                modalBody.textContent = (content === null || content === undefined || content === '')
                    ? '(no content)'
                    : String(content);
                modal.classList.remove('hidden');
            }

            function hideModal() {
                modal.classList.add('hidden');
            }

            document.getElementById('messageModalClose').addEventListener('click', hideModal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) hideModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') hideModal();
            });

            function badge(text, classes) {
                return '<span class="text-white px-2 py-0.5 rounded text-xs font-medium ' + classes + '">' + esc(text) + '</span>';
            }

            const sourceColumn = 1;
            const dependentColumns = [3, 4, 5];

            // yadcf mangles the table selector when generating filter element
            // ids (it turns "#messagesTable" into "-messagesTable"), so rather
            // than reconstruct that id we match the filter <select> by its id
            // suffix, which is always "-<columnNumber>". Robust to id mangling
            // and to whether yadcf renders filters in their own header row.
            function filterSelect(columnNumber) {
                return $('#messagesTable').find('select.yadcf-filter[id$="-' + columnNumber + '"]');
            }

            // Currently selected source value(s); drives the cascade so the
            // dependent dropdowns only offer values for those sources.
            function currentSources() {
                const val = filterSelect(sourceColumn).val();
                if (!val) {
                    return [];
                }
                return Array.isArray(val) ? val : [val];
            }

            // Builds a Select2 ajax config that loads distinct values for a
            // single column on demand, so we never ship the full (unbounded)
            // option list to the page.
            function remoteOptions(field) {
                return {
                    url: '{{ route("messages.filter-options") }}',
                    dataType: 'json',
                    delay: 250,
                    cache: true,
                    data: function (params) {
                        return {
                            field: field,
                            q: params.term || '',
                            page: params.page || 1,
                            source: currentSources(),
                        };
                    },
                    processResults: function (data) {
                        return data;
                    },
                };
            }

            const sourceClasses = {
                discord: 'bg-indigo-600',
                twitter: 'bg-sky-500',
                rscplus: 'bg-amber-600',
                reddit: 'bg-red-600',
                lemmy: 'bg-green-600',
            };

            const visibilityClasses = {
                public: 'bg-green-600',
                private: 'bg-red-600',
                group: 'bg-yellow-500',
            };

            const table = $('#messagesTable').DataTable({
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
                    {
                        data: 'channel_name', name: 'channel_name',
                        render: function (d) {
                            if (d === null || d === undefined || d === '') return '';
                            return '<span style="display:inline-block;max-width:18rem;vertical-align:top;white-space:normal;word-break:break-word;overflow-wrap:anywhere;" title="' + esc(String(d)) + '">' + esc(String(d)) + '</span>';
                        },
                    },
                    { data: 'author_username', name: 'author_username', render: esc },
                    {
                        data: 'visibility', name: 'visibility', width: '90px',
                        render: function (d) {
                            return badge(d, visibilityClasses[d] || 'bg-gray-500');
                        },
                    },
                    { data: 'content', name: 'content', orderable: false, render: renderContent },
                    {
                        data: null, name: 'actions', orderable: false, searchable: false, width: '90px',
                        render: function (row) {
                            return '<button data-id="' + row.id + '" class="ssl-delete bg-red-500 hover:bg-red-700 text-white text-xs font-bold py-1 px-3 rounded">Delete</button>';
                        },
                    },
                ],
            });

            yadcf.init(table, [
                {
                    column_number: 1,
                    filter_type: 'multi_select',
                    data: @json($filters['source']),
                    filter_default_label: 'Source',
                    select_type: 'select2',
                    select_type_options: { width: '100%', placeholder: 'Source' },
                },
                {
                    column_number: 3,
                    filter_type: 'multi_select',
                    data: [],
                    filter_default_label: 'Container',
                    select_type: 'select2',
                    select_type_options: {
                        width: '100%',
                        placeholder: 'Container',
                        minimumInputLength: 0,
                        ajax: remoteOptions('container_name'),
                    },
                },
                {
                    column_number: 4,
                    filter_type: 'multi_select',
                    data: [],
                    filter_default_label: 'Channel',
                    select_type: 'select2',
                    select_type_options: {
                        width: '100%',
                        placeholder: 'Channel',
                        minimumInputLength: 0,
                        ajax: remoteOptions('channel_name'),
                    },
                },
                {
                    column_number: 5,
                    filter_type: 'multi_select',
                    data: [],
                    filter_default_label: 'Author',
                    select_type: 'select2',
                    select_type_options: {
                        width: '100%',
                        placeholder: 'Author',
                        minimumInputLength: 0,
                        ajax: remoteOptions('author_username'),
                    },
                },
                {
                    column_number: 6,
                    filter_type: 'multi_select',
                    data: @json($filters['visibility']),
                    filter_default_label: 'Visibility',
                    select_type: 'select2',
                    select_type_options: { width: '100%', placeholder: 'Visibility' },
                },
            ]);

            // When the source selection changes, the container/channel/author
            // selections may no longer belong to the chosen source(s), so reset
            // them. Their option lists already re-query with the new source on
            // next open via remoteOptions(). Only reset when something is set,
            // to avoid needless redraws on the common (empty) case.
            $('#messagesTable').on('change', 'select.yadcf-filter', function () {
                const id = $(this).attr('id') || '';
                if (!id.endsWith('-' + sourceColumn)) {
                    return;
                }
                const hasDependentSelection = dependentColumns.some(function (col) {
                    const val = filterSelect(col).val();
                    return val && val.length;
                });
                if (hasDependentSelection) {
                    yadcf.exResetFilters(table, dependentColumns, true);
                    table.draw(false);
                }
            });

            $('#messagesTable tbody').on('click', '.ssl-content', function () {
                const rowData = table.row($(this).closest('tr')).data();
                showModal(rowData ? rowData.content : null);
            });

            $('#messagesTable tbody').on('click', '.ssl-delete', function () {
                const id = $(this).data('id');
                if (!confirm('Delete message #' + id + '? This cannot be undone.')) {
                    return;
                }
                axios.post('/messages/' + id + '/delete', { _token: '{{ csrf_token() }}' })
                    .then(function () {
                        table.ajax.reload(null, false);
                    })
                    .catch(function (error) {
                        alert('Error deleting message: ' + error);
                    });
            });
        });
    </script>
</x-app-layout>
