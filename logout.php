<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$token = (string) ($_POST['csrf_token'] ?? '');

if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    http_response_code(419);
    exit('Permintaan logout tidak valid.');
}

logoutUser();
header('Location: login.php?logged_out=1');
exit;
