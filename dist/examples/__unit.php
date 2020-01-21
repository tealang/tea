<?php
namespace tea\examples;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR; // the workspace/vendor path
require_once $super_path . 'tea/dist/builtin/__unit.php'; // the builtins

#internal
const NUM = 10;
#internal
const MIN_FLOAT = 1.11e-16;

function factorial(int $n): int {
	if ($n > 1) {
		return $n * factorial($n - 1);
	}

	return 1;
}

function fib_closure(): callable {
	$a = 0;
	$b = 1;
	return function () use(&$b, &$a) {
		$c = $b;
		$b = $a + $b;
		$a = $c;
		return $a;
	};
}

function fib_generator(int $num = 9): \Generator {
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

function say_hello(string $name = 'World') {
	echo 'Hello, ' . $name . '!', NL;
}

function set_field(string $key, string $value, array &$dict) {
	$dict[$key] = $value;
}

function show_file_path(string $filename = null) {
	if ($filename === null) {
		$filename = __FILE__;
	}
	else {
		$filename = realpath($filename);
	}

	echo $filename, NL;
}


// program end

# --- generates ---
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
