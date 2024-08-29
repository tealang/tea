<?php
namespace tests\examples;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;


// program end

// autoloads
const __AUTOLOADS = [
	'tests\examples\IFib' => 'dist/fib_class.php',
	'tests\examples\IFibTrait' => 'dist/fib_class.php',
	'tests\examples\Fib' => 'dist/fib_class.php',
	'tests\examples\SQLitePDO' => 'dist/pdo_sqlite.php',
	'tests\examples\IBaseView' => 'dist/view.php',
	'tests\examples\IBaseViewTrait' => 'dist/view.php',
	'tests\examples\ListView' => 'dist/view.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
