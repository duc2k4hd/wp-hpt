<?php
// Bật hiển thị tất cả lỗi
// error_reporting(E_ALL);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);

// // Log lỗi ra file
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/php-error.log');

// // Bắt Exception
// set_exception_handler(function ($e) {
//     echo "<pre style='background:#111;color:#0f0;padding:15px'>";
//     echo "EXCEPTION:\n";
//     echo $e->getMessage() . "\n";
//     echo $e->getFile() . ":" . $e->getLine() . "\n\n";
//     echo $e->getTraceAsString();
//     echo "</pre>";
// });

// // Bắt Error (Fatal, Warning, Notice…)
// set_error_handler(function ($severity, $message, $file, $line) {
//     echo "<pre style='background:#111;color:#ff0;padding:15px'>";
//     echo "ERROR:\n";
//     echo $message . "\n";
//     echo $file . ":" . $line;
//     echo "</pre>";
// });

// // Bắt Fatal Error (shutdown)
// register_shutdown_function(function () {
//     $error = error_get_last();
//     if ($error !== NULL) {
//         echo "<pre style='background:#300;color:#fff;padding:15px'>";
//         echo "FATAL ERROR:\n";
//         print_r($error);
//         echo "</pre>";
//     }
// });

/**
 * Front to the WordPress application. This file doesn't do anything, but loads
 * wp-blog-header.php which does and tells WordPress to load the theme.
 *
 * @package WordPress
 */

/**
 * Tells WordPress to load the WordPress theme and output it.
 *
 * @var bool
 */
define( 'WP_USE_THEMES', true );

/** Loads the WordPress Environment and Template */
require __DIR__ . '/wp-blog-header.php';
