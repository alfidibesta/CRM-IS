<?php
/**
 * Router for the PHP built-in server (development only).
 *
 *     php -S localhost:8000 router.php
 *
 * The built-in server has no .htaccess, so it would happily hand out
 * schema.sql, README.md, lib/*.php and the evidence folder. This router
 * mirrors those denies: private paths answer 404, real files are served,
 * everything else goes to index.php.
 */

$path = '/' . ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/', '/');

$private = preg_match('#^/(uploads|lib|tests|logs)(/|$)#', $path) === 1
    || preg_match('#\.(sql|md|log|ini|json|env|sh|bak)$#i', $path) === 1
    || $path === '/router.php';

if ($private) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    return true;
}

if (is_file(__DIR__ . $path)) {
    return false; // let the built-in server send the asset
}

require __DIR__ . '/index.php';
