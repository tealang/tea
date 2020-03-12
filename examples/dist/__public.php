<?php
namespace tea\examples;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR; // the workspace/vendor path
require_once $super_path . 'tea/builtin/dist/__public.php'; // the builtins

// program end

// autoloads
const __AUTOLOADS = [
	'tea\examples\IFib' => 'fib_class.php',
	'tea\examples\IFibTrait' => 'fib_class.php',
	'tea\examples\Fib' => 'fib_class.php',
	'tea\examples\SQLitePDO' => 'pdo_sqlite.php',
	'tea\examples\IBaseView' => 'view.php',
	'tea\examples\IBaseViewTrait' => 'view.php',
	'tea\examples\ListView' => 'view.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
