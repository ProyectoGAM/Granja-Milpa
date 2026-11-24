<?php

function queryExamples(string $value): void
{
    // ruleid: gam-php-interpolated-sql
    DB::select('SELECT name FROM items WHERE id = ' . $value);
    // ok: gam-php-interpolated-sql
    DB::select('SELECT name FROM items WHERE id = ?', [$value]);

    // ruleid: gam-php-shell
    shell_exec('fixture-command ' . $value);
    // ok: gam-php-shell
    shell_exec('fixture-command');
}

function additionalExamples(string $value, $curl): void
{
    // ruleid: gam-php-eval
    eval($value);
    // ok: gam-php-eval
    json_decode($value);
    // ruleid: gam-php-unserialize
    unserialize($value);
    // ok: gam-php-unserialize
    json_decode($value, true);
    // ruleid: gam-php-tls-disabled
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    // ok: gam-php-tls-disabled
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
    // ruleid: gam-php-debug-output
    phpinfo();
    // ok: gam-php-debug-output
    phpversion();
}
