import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

function executeSearch() {
    let type = document.getElementById('searchType').value;
    let query = document.getElementById('globalSearch').value;
    if (type === 'messages') {
        window.location.href = '/messages?search=' + encodeURIComponent(query);
    } else if (type === 'users') {
        window.location.href = '/users/?search=' + encodeURIComponent(query);
    }
}

document.getElementById('globalSearch').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        executeSearch();
    }
});

document.getElementById('searchButton').addEventListener('click', function() {
    executeSearch();
});
