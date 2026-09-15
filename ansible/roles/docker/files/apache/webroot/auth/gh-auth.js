(function (window) {
    'use strict';

    function authedFetch(url, opts) {
        return fetch(url, opts);
    }

    window.GHAuth = {
        authedFetch: authedFetch
    };
}(window));
