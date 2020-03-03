<?php
/**
 * The PHPDemoUnit
 *
 */

namespace PHPDemoUnit;

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/functions.php';

// Please do not modify the following contents






# --- generates ---
const __AUTOLOADS = [
	'tea\tests\PHPDemoUnit\BaseInterface' => 'InterfaceDemo.php',
	'tea\tests\PHPDemoUnit\Interface1' => 'InterfaceDemo.php',
	'tea\tests\PHPDemoUnit\NS1\Demo' => 'NS1/Demo.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require __DIR__ . DIRECTORY_SEPARATOR . __AUTOLOADS[$class];
});

// end
