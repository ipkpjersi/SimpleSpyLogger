<x-app-layout>
    <x-slot name="title">
        {{ config('app.name', 'Laravel') }} - Invite Codes
    </x-slot>

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Invite Codes') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div id="message-modal" class="fixed inset-0 flex items-center justify-center hidden z-50">
                <div class="bg-gray-100 dark:bg-black p-4 rounded-lg text-center">
                    <p id="modal-message" class="mb-4">Message goes here.</p>
                    <button type="button" onclick="closeModal()" class="px-4 py-2 bg-blue-500 text-white rounded-lg">
                        OK
                    </button>
                </div>
            </div>
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="mb-4">
                        <button id="generateCodes" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-700 mb-2 mr-2">Generate New Codes</button>
                        <button id="revokeCodes" class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-700 mb-2 mr-2">Revoke Unused Codes</button>
                    </div>
                    <table id="inviteCodesTable" class="display">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Code</th>
                                <th>Used</th>
                                <th>Username</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
       function closeModal() {
           document.getElementById('message-modal').classList.add('hidden');
       }
    </script>
    <script type="module">
        import '/js/jquery.dataTables.yadcf.js';
        $(document).ready(function() {
            var table = $('#inviteCodesTable').DataTable({
                processing: true,
                serverSide: true,
                ajax: '{{ route("invite-codes-data") }}',
                columns: [
                    { data: 'id', name: 'id' },
                    { data: 'code', name: 'code' },
                    { data: 'used', name: 'used', render: function(data, type, row) {
                        return data === 1 ? "Yes" : "No";
                    }},
                    { data: 'username', name: 'username', render: function(data, type, row) {
                        return data !== null ? data : "N/A";
                    }},
                    { data: 'created_at', name: 'created_at' }
                ]
            });

            $('#generateCodes').click(function() {
                axios.post('{{ route("generate-invite-codes") }}', {
                    _token: '{{ csrf_token() }}'
                }).then(function(response) {
                    showModal(response.data.message);
                    table.ajax.reload();
                }).catch(function(error) {
                    showModal('Error: ' + error.response.data.message);
                });
            });

            $('#revokeCodes').click(function() {
                axios.post('{{ route("revoke-unused-invite-codes") }}', {
                    _token: '{{ csrf_token() }}'
                }).then(function(response) {
                    showModal(response.data.message);
                    table.ajax.reload();
                }).catch(function(error) {
                    showModal('Error: ' + error.response.data.message);
                });
            });
            function showModal(message) {
                document.getElementById('modal-message').textContent = message;
                document.getElementById('message-modal').classList.remove('hidden');
            }

            function closeModal() {
                document.getElementById('message-modal').classList.add('hidden');
            }

            window.addEventListener("click", function (event) {
                if (event.target === document.getElementById("message-modal")) {
                    closeModal();
                }
            });

            window.addEventListener("keydown", function (e) {
                if (e.key === "Escape") {
                    closeModal();
                }
            });
        });
    </script>
</x-app-layout>
