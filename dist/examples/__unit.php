<?php
namespace tea\examples;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR; // the workspace/vendor path
require_once $super_path . 'tea/dist/builtin/__unit.php'; // the builtins

#internal
const MIN_FLOAT = 1.11e-16;

function fib_with_closure(): callable {
	$a = 0;
	$b = 1;
	return function () use(&$b, &$a) {
		$c = $b;
		$b = $a + $b;
		$a = $c;
		return $a;
	};
}

function fib_with_generator(int $num = 9): \Generator {
	$c = null;

	$a = 0;
	$b = 1;
	for ($i = 0; $i <= $num; $i += 1) {
		$c = $b;
		$b = $a + $b;
		$a = $c;
		yield $a;
	}
}


// program end

# --- generates ---
const __AUTOLOADS = [
	'tea\examples\IFib' => 'fib_with_class.php',
	'tea\examples\IFibTrait' => 'fib_with_class.php',
	'tea\examples\Fib' => 'fib_with_class.php',
	'tea\examples\SQLitePDO' => 'pdo_sqlite.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
