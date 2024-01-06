<?php
namespace tea\examples;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $super_path . 'tea-modules/tea/builtin/__public.php';

// program end

// autoloads
const __AUTOLOADS = [
	'tea\examples\IFib' => 'dist/fib_class.php',
	'tea\examples\IFibTrait' => 'dist/fib_class.php',
	'tea\examples\Fib' => 'dist/fib_class.php',
	'tea\examples\SQLitePDO' => 'dist/pdo_sqlite.php',
	'tea\examples\IBaseView' => 'dist/view.php',
	'tea\examples\IBaseViewTrait' => 'dist/view.php',
	'tea\examples\ListView' => 'dist/view.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
