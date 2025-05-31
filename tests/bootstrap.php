<?php

/**
 * Bootstrap file for PHPUnit tests
 */

// Find the autoload.php file
$autoloadFile = dirname(dirname(dirname(dirname(__DIR__)))) . '/autoload.php';
if (!file_exists($autoloadFile)) {
    echo 'Could not find autoload.php file, make sure you ran composer install' . PHP_EOL;
    exit(1);
}

// Include the composer autoloader
require_once $autoloadFile;
