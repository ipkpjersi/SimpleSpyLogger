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

const globalSearch = document.getElementById('globalSearch');
const searchButton = document.getElementById('searchButton');

if (globalSearch !== null) {
    globalSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            executeSearch();
        }
    });
}

if (searchButton !== null) {
    searchButton.addEventListener('click', function() {
        executeSearch();
    });
}
