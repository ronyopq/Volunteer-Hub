<?php
// Compatibility redirect: v1.0.0 used /public/index.php; v1.0.2 uses /index.php.
declare(strict_types=1);
$params = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Cache-Control: no-store, max-age=0');
header('Location: ../index.php' . ($params !== '' ? '?' . $params : ''), true, 302);
exit;
