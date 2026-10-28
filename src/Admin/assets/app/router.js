/*!
 * Grocery Restable admin - a tiny hash router with an Express-like API.
 *
 *   const router = GR.Router();
 *   router.use(async (req, res, next) => { ...; await next(); });
 *   router.get('/data/:conn/:table/:tab?', (req, res) => { req.params.table; req.query.page; });
 *   router.redirect('/resources', '/api');
 *   router.start();
 */
(function (GR) {
    'use strict';

    /** '/data/:conn/:tab?' → { regexp, keys: ['conn', 'tab'] } */
    function compile(pattern) {
        const keys = [];
        const source = pattern.replace(/\/:(\w+)(\?)?/g, (match, key, optional) => {
            keys.push(key);
            return optional ? '(?:/([^/]+))?' : '/([^/]+)';
        });
        return { regexp: new RegExp('^' + source + '/?$'), keys };
    }

    function parseLocation() {
        const raw = window.location.hash.replace(/^#/, '') || '/';
        const [path, search = ''] = raw.split('?');
        return {
            path: path.startsWith('/') ? path : '/' + path,
            query: Object.fromEntries(new URLSearchParams(search)),
        };
    }

    function Router() {
        const middleware = [];
        const routes = [];
        let fallback = () => {};
        let queue = Promise.resolve();

        async function handle() {
            const { path, query } = parseLocation();
            const found = match(path);
            const req = { path, query, params: found ? found.params : {}, route: found ? found.route.pattern : null, hash: window.location.hash };
            const res = {
                /** Replace the current history entry (no extra back step) and handle it now. Accepts '/path' or '#/path'. */
                redirect(to) {
                    history.replaceState(null, '', '#' + to.replace(/^#/, ''));
                    return handle(); // not queued: it runs inside the current navigation
                },
            };
            const chain = [...middleware, ...(found ? found.route.handlers : [fallback])];
            let index = 0;
            const next = async () => {
                const fn = chain[index++];
                if (fn) await fn(req, res, next);
            };
            await next();
        }

        function match(path) {
            for (const route of routes) {
                const m = route.regexp.exec(path);
                if (!m) continue;
                const params = {};
                route.keys.forEach((key, i) => { if (m[i + 1] !== undefined) params[key] = decodeURIComponent(m[i + 1]); });
                return { route, params };
            }
            return null;
        }

        const router = {
            /** Middleware for every navigation: (req, res, next). */
            use(fn) {
                middleware.push(fn);
                return router;
            },

            get(pattern, ...handlers) {
                routes.push({ pattern, handlers, ...compile(pattern) });
                return router;
            },

            /** Permanent alias, e.g. for old bookmarks. `to` may be a function of req. */
            redirect(from, to) {
                return router.get(from, (req, res) => res.redirect(typeof to === 'function' ? to(req) : to));
            },

            /** Handler when no route matches. */
            otherwise(fn) {
                fallback = fn;
                return router;
            },

            /**
             * Navigations run one after the other: a slow page that finishes late can never
             * overwrite the page the user navigated to afterwards.
             */
            dispatch() {
                const run = queue.then(handle);
                queue = run.catch(() => {});
                return run;
            },

            start() {
                window.addEventListener('hashchange', () => router.dispatch());
                return router.dispatch();
            },
        };
        return router;
    }

    GR.Router = Router;
})(window.GR = window.GR || {});
