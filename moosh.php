#!/usr/bin/php
<?php
/**
 * moosh2 — Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$vendorDir = getenv('COMPOSER_VENDOR_DIR');

if ($vendorDir && file_exists($vendorDir . '/autoload.php')) {
    $autoloadPath = $vendorDir . '/autoload.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    // 2. Standard local vendor directory
    $autoloadPath = __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../autoload.php')) {
    // 3. Installed as a dependency in another project
    $autoloadPath = __DIR__ . '/../../autoload.php';
} else {
    fwrite(STDERR, "Error: Unable to find Composer's autoload.php file.\n");
    fwrite(STDERR, "Please run 'composer install' or check your COMPOSER_VENDOR_DIR.\n");
    exit(1);
}

require $autoloadPath;

use Moosh2\Application;

$app = new Application();
$app->run();
