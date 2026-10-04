<?php
// ---------------------------------------------------------------
// TNTS Inventory System - settings
// ---------------------------------------------------------------
// Database (XAMPP defaults: user "root" with no password)
define('DB_HOST', 'localhost');
define('DB_NAME', 'tnts_inventory');
define('DB_USER', 'root');
define('DB_PASS', '');

// Folder name under htdocs, no trailing slash.
// If you put the project somewhere else, change this (use '' for the web root).
define('BASE_URL', '/tnts');

define('APP_NAME', 'TNTS Inventory System');

// Seconds of inactivity before a user is logged out automatically
define('SESSION_TIMEOUT', 1800);

date_default_timezone_set('Asia/Manila');
