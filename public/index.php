<?php
declare(strict_types=1);

$base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
header('Location: ' . ($base === '' ? '' : $base) . '/admin/cars.php', true, 302);
exit;
