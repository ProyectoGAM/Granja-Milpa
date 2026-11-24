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
