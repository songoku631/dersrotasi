<?php
declare(strict_types=1);

function endpointCheck(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }

$root = dirname(__DIR__);
$router = (string) file_get_contents($root . '/public/index.php');
$env = (string) file_get_contents($root . '/src/Config/Env.php');

$authenticatedPomodoro = strpos($router, "if (str_starts_with(\$path, '/api/pomodoro')) {");
$auth = strpos($router, "\$uid = \$authenticate()['uid'];", $authenticatedPomodoro);
$endpoint = strpos($router, "'/api/pomodoro/turn-credentials'", $authenticatedPomodoro);
endpointCheck($authenticatedPomodoro !== false && $auth !== false && $endpoint !== false && $auth < $endpoint, 'TURN credential endpointi Firebase auth arkasında olmalı.');
endpointCheck(str_contains($router, "\$pomodoro->rateTurnCredentials(\$uid);") && str_contains($router, 'turnSharedSecret()'), 'TURN endpointi rate limit ve backend secret kullanmalı.');
endpointCheck(!str_contains($router, 'TURN_SHARED_SECRET') && !str_contains($router, 'TURN_SECRET'), 'Secret router veya response içine yazılmamalı.');
endpointCheck(str_contains($env, "get('TURN_SHARED_SECRET')") && !str_contains($env, "get('TURN_SECRET')"), 'TURN shared secret yalnızca backend environmenttan okunmalı.');

echo "TurnCredentialsEndpointTest: OK\n";
