<?php
/**
 * Router for the PHP built-in server (development only).
 *
 *     php -S localhost:8000 router.php
 *
 * The built-in server has no .htaccess, so without this file it would hand out
 * schema.sql, a database dump in backups/, lib/*.php and the evidence folder.
 *
 * The rule is default-deny: a request is served as a file only when it is one
 * of the shell's own static assets (assets/*, sw.js, offline.html). Dotfiles
 * and dot segments, the private folders (uploads, lib, tests, logs, tools,
 * backups), archives and database files answer 404; everything else goes to
 * index.php. A new private folder is therefore not exposed just because
 * nobody remembered to add it to a deny list.
 *
 * tests/run.php includes this file for router_denied_path() and
 * router_servable_path(); the CLI guard keeps the script from running there.
 */

/**
 * True when a URL path must never be served or routed.
 *
 * Decodes once first, so "%2e%2e" and a NUL byte are judged as written rather
 * than slipping past the segment checks.
 */
function router_denied_path(string $path): bool
{
    $path = rawurldecode($path);
    if (str_contains($path, "\0")) {
        return true;
    }

    // Dotfiles and dot segments at any depth: .htaccess, .git/config,
    // assets/../.htaccess, /.
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..' || ($segment !== '' && $segment[0] === '.')) {
            return true;
        }
    }

    if (preg_match('#^/(uploads|lib|tests|logs|tools|backups)(/|$)#i', $path) === 1) {
        return true;
    }
    if (strcasecmp($path, '/router.php') === 0) {
        return true;
    }

    // Archives, database files and backups are never static content.
    return preg_match(
        '#\.(sql|gz|tgz|tar|bz2|7z|rar|zip|md|log|ini|json|env|sh|bak|swp|swo|old|orig|dist|sqlite|db)$#i',
        $path
    ) === 1;
}

/** True when a path is one of the shell's public static files. */
function router_servable_path(string $path): bool
{
    if (router_denied_path($path)) {
        return false;
    }

    $path = rawurldecode($path);
    if ($path === '/sw.js' || $path === '/offline.html') {
        return true;
    }

    return preg_match(
        '#^/assets/[A-Za-z0-9._-]+\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf)$#i',
        $path
    ) === 1;
}

/** Answer a private or missing path without involving the app. */
function router_not_found(): bool
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    return true;
}

// Included by the test suite for the rules above; only the built-in server
// (PHP_SAPI "cli-server") runs the dispatch below.
if (PHP_SAPI === 'cli') {
    return;
}

$path = '/' . ltrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'), '/');

if (router_denied_path($path)) {
    return router_not_found();
}

if (!router_servable_path($path)) {
    // A missing asset is a 404, not a page of HTML with a 200.
    if (str_starts_with($path, '/assets/')) {
        return router_not_found();
    }
    require __DIR__ . '/index.php';
    return true;
}

// realpath() plus the docroot prefix is belt-and-braces: the allowlist above
// already admits no dot segment, so this can only confirm it.
$file = realpath(__DIR__ . $path);
if ($file === false || !is_file($file) || !str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) {
    return router_not_found();
}

return false; // let the built-in server send the asset
