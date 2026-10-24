/*!
 * Grocery Restable admin — asset loader.
 *
 * The libraries are loaded from the jsDelivr CDN first (see views/head.php and views/foot.php).
 * When the CDN is unreachable (offline / blocked), this file loads the identical local copies
 * from assets/vendor, then starts the admin application. It must stay an external file:
 * the Content-Security-Policy forbids inline scripts.
 */
(function () {
    'use strict';

    var current = document.currentScript;
    var base = current.src.replace(/loader\.js(\?.*)?$/, '');
    var version = (current.src.match(/\?v=([A-Za-z0-9]+)/) || [])[1];
    var startApp = current.getAttribute('data-app') === '1';

    function addStyle(path) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = base + path;
        // Insert before admin.css so the admin theme keeps overriding the libraries.
        var theme = document.querySelector('link[href*="admin.css"]');
        document.head.insertBefore(link, theme);
    }

    function probe(className, property) {
        var el = document.createElement(className === 'fa-solid' ? 'i' : 'span');
        el.className = className;
        el.style.visibility = 'hidden';
        document.body.appendChild(el);
        var value = window.getComputedStyle(el, className === 'fa-solid' ? '::before' : null).getPropertyValue(property);
        document.body.removeChild(el);
        return value;
    }

    // Stylesheets: detect whether the CDN versions were applied.
    if (probe('visually-hidden', 'position') !== 'absolute') addStyle('vendor/bootstrap/bootstrap.min.css');
    if (probe('fa-solid', 'font-family').indexOf('Font Awesome') === -1) addStyle('vendor/fontawesome/css/all.min.css');
    if (startApp && probe('hljs', 'color') !== 'rgb(201, 209, 217)') addStyle('vendor/highlightjs/github-dark.min.css');

    if (!startApp) return;

    // Scripts: load missing libraries in order, then the application.
    var queue = [];
    if (!window.jQuery) queue.push('vendor/jquery/jquery.min.js');
    if (!window.bootstrap) queue.push('vendor/bootstrap/bootstrap.bundle.min.js');
    if (!window.hljs) queue.push('vendor/highlightjs/highlight.min.js');
    queue.push('admin.js' + (version ? '?v=' + version : ''));

    queue.forEach(function (path) {
        var script = document.createElement('script');
        script.src = base + path;
        script.async = false; // keep execution order
        document.body.appendChild(script);
    });
})();
