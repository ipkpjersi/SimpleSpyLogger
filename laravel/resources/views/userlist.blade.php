<x-app-layout>
    <x-slot name="title">
        {{ config('app.name', 'Laravel') }} - User List
    </x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('User List') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <table style="width:100%" id="userTable" class="display text-gray-900 dark:text-gray-100">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Admin</th>
                                <th>Joined</th>
                                @if (auth()->user() != null && auth()->user()->isAdmin())
                                    <th>Actions</th>
                                @endif
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
        import '/js/jquery.dataTables.yadcf.js';

        $(document).ready(function () {
            let initialSearch = new URLSearchParams(window.location.search).get('search') || "";
            let columns = [
                { data: 'id', name: 'id' },
                { data: 'username', name: 'username' },
                { data: 'is_admin', name: 'is_admin', render: function(data, type, row) {
                    return data === 1 ? "Yes" : "No";
                }},
                { data: 'created_at', name: 'created_at' },
            ];

            @if (auth()->user() != null && auth()->user()->isAdmin())
                columns.push({
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    let actions = '';
                    if(row.is_admin != 1) {
                        if(row.is_banned == 1) {
                            actions += `<button data-user-id="${data.id}" class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded unbanUser">Unban</button>`;
                        } else {
                            actions += `<button data-user-id="${data.id}" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded banUser">Ban</button>`;
                        }
                    }
                    return actions;
                }
            });
            @endif

            let dataTable = $('#userTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: '{{ route('users.data') }}',
                order: [3, 'desc'],
                columns: columns,
                search: { search: initialSearch },
                responsive: true,
            });

            yadcf.init(dataTable, [
                {
                    column_number: 1,
                    filter_type: "text"
                },
                {
                    column_number: 2,
                    filter_type: "select",
                    data: [
                        { value: "1", label: "Yes" },
                        { value: "0", label: "No" },
                    ],
                    filter_default_label: "All"
                },
            ]);

            // Restrict sorting to the column title (same as the messages view):
            // DataTables makes the whole header cell a sort toggle, including
            // the filter area. Remove its whole-cell click handler and make a
            // .ssl-sort title handle the only sort trigger.
            $('#userTable thead th').off('click.DT');
            $('#userTable thead th').each(function () {
                const th = this;
                if (!(th.classList.contains('sorting') || th.classList.contains('sorting_asc') || th.classList.contains('sorting_desc'))) {
                    return;
                }
                const title = th.childNodes[0];
                if (title && title.nodeType === 3 && title.textContent.trim() !== '') {
                    const handle = document.createElement('span');
                    handle.className = 'ssl-sort';
                    handle.textContent = title.textContent;
                    const ind = document.createElement('span');
                    ind.className = 'ssl-sort-ind';
                    ind.setAttribute('aria-hidden', 'true');
                    handle.appendChild(ind);
                    th.replaceChild(handle, title);
                }
            });
            $('#userTable thead').on('click', '.ssl-sort', function () {
                const th = $(this).closest('th')[0];
                const colIdx = Array.prototype.indexOf.call(th.parentNode.children, th);
                const cur = dataTable.order();
                const dir = (cur.length && cur[0][0] === colIdx && cur[0][1] === 'asc') ? 'desc' : 'asc';
                dataTable.order([colIdx, dir]).draw();
            });

            $(document).on('click', '.banUser', function() {
                let userId = $(this).data('user-id');
                axios.post(`/users/${userId}/ban`, {
                    _token: '{{ csrf_token() }}'
                })
                .then(function(response) {
                    alert(response.data.message);
                    dataTable.ajax.reload(); // Refresh the table
                })
                .catch(function(error) {
                    alert('Error banning user: ' + error);
                });
            });

            $(document).on('click', '.unbanUser', function() {
                let userId = $(this).data('user-id');
                axios.post(`/users/${userId}/unban`, {
                    _token: '{{ csrf_token() }}'
                })
                .then(function(response) {
                    alert(response.data.message);
                    dataTable.ajax.reload(); // Refresh the table
                })
                .catch(function(error) {
                    alert('Error unbanning user: ' + error);
                });
            });
        });
    </script>
    <style>
        #yadcf-filter--userTable-2 {
            max-width: 138px;
            padding-right: 1rem;
        }
    </style>
</x-app-layout>
