<?php
declare(strict_types=1);

/**
 * Front controller.
 *
 * Everything reaches the application through this file. The rest of the
 * codebase — app/, config/, uploads/, storage/, logs/, backups/ — sits OUTSIDE
 * this directory and is not reachable over the web at all.
 */

// Errors are logged, never printed: a stack trace in a response body leaks
// paths, queries and sometimes credentials. app.debug can enable display for
// local development.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/** @var \App\Core\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

if ((bool) \App\Core\Config::get('app.debug', false)) {
    ini_set('display_errors', '1');
}

$request = \App\Core\Request::capture();
$app->handle($request)->send();
