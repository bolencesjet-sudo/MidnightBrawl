<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/security.php';
$cases = [
    ['http://localhost/TFv1/', 'http://localhost', true],
    ['https://EXAMPLE.test:443/app/', 'https://example.test', true],
    ['http://example.test', 'http://EXAMPLE.test:80', true],
    ['http://localhost:8080/TFv1/', 'http://localhost:8080', true],
    ['http://[::1]:8080/TFv1/', 'http://[::1]:8080', true],
    ['https://example.test/', 'https://example.test/', true],
    ['https://example.test', 'http://example.test', false],
    ['http://localhost:8080', 'http://localhost', false],
    ['http://localhost', 'http://127.0.0.1', false],
    ['https://example.test', 'https://preview.example.test', false],
    ['https://example.test', 'https://example.test.attacker.test', false],
    ['https://example.test', 'https://example.test@attacker.test', false],
    ['https://example.test', 'https://example.test/app', false],
    ['https://example.test', 'https://example.test?query=1', false],
    ['https://example.test', 'https://example.test#fragment', false],
    ['https://example.test', 'null', false],
    ['https://example.test', 'https://example.test https://attacker.test', false],
    ['https://example.test', 'https://example.test\\@attacker.test', false],
    ['https://example.test', 'https://example.test:99999', false],
    ['invalid-configuration', 'https://example.test', false],
    ['https://example.test', '', true],
    ['', 'http://localhost', true],
];
foreach ($cases as [$configured, $origin, $expected]) {
    putenv('TF_APP_URL='.$configured);
    if (requestOriginAllowed($origin) !== $expected) throw new RuntimeException('Unexpected origin decision: '.$configured.' / '.$origin);
}
echo 'PASS: '.count($cases)." origin checks, including URL paths, default ports and rejected foreign origins.\n";
