<?php

declare(strict_types=1);

$origin = 'http://web:8080';
$probe = [
    '/' => [200, 'text/html'],
    '/auth' => [200, 'text/html'],
    '/api/v1/health' => [200, 'application/json'],
    '/api/v1/me' => [401, 'application/problem+json'],
    '/sanctum/csrf-cookie' => [204, null],
];

$context = stream_context_create(['http' => [
    'timeout' => 5,
    'ignore_errors' => true,
    'follow_location' => 0,
    'header' => "Accept: application/json\r\nOrigin: $origin\r\n",
]]);

$outcome = 0;
$results = [];
foreach ($probe as $route => [$status, $contentType]) {
    $stream = @fopen($origin.$route, 'r', false, $context);
    if ($stream === false) {
        $results[] = ['path' => $route, 'transport' => false];
        $outcome = 20;
        continue;
    }

    $rawHeaders = stream_get_meta_data($stream)['wrapper_data'];
    $body = stream_get_contents($stream, 65536);
    fclose($stream);
    preg_match('/^HTTP\/\S+ (\d{3})\b/', array_shift($rawHeaders), $match);
    $observed = (int) ($match[1] ?? 0);
    $headers = [];
    foreach ($rawHeaders as $header) {
        [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
        $headers[strtolower($name)][] = trim($value);
    }

    $actualContentType = explode(';', $headers['content-type'][0] ?? '', 2)[0];
    $passed = $observed === $status && ($contentType === null || $actualContentType === $contentType);
    $cookies = [];
    if ($route === '/api/v1/health') {
        $passed = $passed && (json_decode($body, true)['status'] ?? null) === 'ok';
    } elseif ($route === '/api/v1/me') {
        $problem = json_decode($body, true);
        $passed = $passed && is_array($problem) && ($problem['status'] ?? null) === 401
            && array_intersect(['trace', 'exception', 'file'], array_keys($problem)) === [];
    } elseif (in_array($route, ['/', '/auth'], true)) {
        $passed = $passed
            && strtolower($headers['x-content-type-options'][0] ?? '') === 'nosniff'
            && in_array(strtolower($headers['x-frame-options'][0] ?? ''), ['deny', 'sameorigin'], true);
    } elseif ($route === '/sanctum/csrf-cookie') {
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $attributes = explode(';', $cookie);
            $pair = array_shift($attributes);
            $name = explode('=', $pair, 2)[0];
            $flags = array_map(static fn (string $attr): string => strtolower(trim($attr)), $attributes);
            $cookies[$name] = [
                'secure' => in_array('secure', $flags, true),
                'httpOnly' => in_array('httponly', $flags, true),
                'sameSiteLax' => in_array('samesite=lax', $flags, true),
            ];
        }
        $passed = $passed
            && ($cookies['gam_session'] ?? null) === ['secure' => true, 'httpOnly' => true, 'sameSiteLax' => true]
            && ($cookies['XSRF-TOKEN']['httpOnly'] ?? null) === false;
    }

    $results[] = ['path' => $route, 'status' => $observed, 'contentType' => $actualContentType, 'passed' => $passed, 'cookies' => $cookies];
    if (!$passed && $outcome !== 20) {
        $outcome = 10;
    }
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($outcome);
