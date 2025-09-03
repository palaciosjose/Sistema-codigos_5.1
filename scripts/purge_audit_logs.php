<?php
require_once __DIR__ . '/../config/path_constants.php';
require_once PROJECT_ROOT . '/shared/AuditLogger.php';

use Shared\AuditLogger;

$days = isset($argv[1]) ? (int)$argv[1] : 90;
AuditLogger::purge($days);
echo "Audit logs older than $days days removed." . PHP_EOL;
