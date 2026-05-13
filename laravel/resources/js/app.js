import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

function executeSearch() {
    let type = document.getElementById('searchType').value;
    let query = document.getElementById('globalSearch').value;
    if (type === 'option') {
        window.location.href = '/option/?search=' + encodeURIComponent(query);
    } else if (type === 'other') {
        window.location.href = '/other/?search=' + encodeURIComponent(query);
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
