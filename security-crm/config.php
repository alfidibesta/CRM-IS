<?php
/**
 * Configuration for Laragon (Apache + MySQL).
 *
 * Laragon ships with MySQL user "root" and an empty password, which is the
 * default here. Point the SECOPS_DB_* environment variables at a dedicated
 * account before the app leaves a development machine.
 */

return [
    'host'     => getenv('SECOPS_DB_HOST') ?: '127.0.0.1',
    'port'     => (int) (getenv('SECOPS_DB_PORT') ?: 3306),
    'user'     => getenv('SECOPS_DB_USER') ?: 'root',
    'password' => getenv('SECOPS_DB_PASS') ?: '',
    'dbname'   => getenv('SECOPS_DB_NAME') ?: 'security_crm',
    'charset'  => 'utf8mb4',

    /*
     * Show the demo account list on the sign-in screen. Set the environment
     * variable SECOPS_DEMO to "0" (or change this to false) for a real
     * deployment — the accounts themselves are seeded on first run either way.
     */
    'demo_mode' => getenv('SECOPS_DEMO') !== '0',

    /*
     * Intranet addressing: addresses (or CIDRs) of the reverse proxies whose
     * X-Forwarded-For / X-Real-IP headers may be believed. The client address
     * is read from those headers only when the request's direct peer is in this
     * list, so nobody can forge their address in the audit trail.
     *
     * SECOPS_TRUSTED_PROXIES="10.120.10.5,10.0.0.0/8" — the default trusts this
     * machine only, which is right when Apache/nginx proxies on localhost.
     */
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) (getenv('SECOPS_TRUSTED_PROXIES') ?: '127.0.0.1/32,::1/128'))
    ), 'strlen')),
];
