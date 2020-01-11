<?php
namespace tea\docs;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR; // the workspace/vendor path
require_once $super_path . 'tea/dist/builtin/__unit.php'; // the builtins

#internal
const STRING_CONST = 'abcdefg';
#public
const PI = 3.1415926;
#public
const ARRAY_CONST = [1, 2];

function demo_function1(string $message) {
	echo 'this function can only be called by local unit', NL;
}

function demo_function2(string $message = 'with a default value') {
	echo 'this function can be called by local or foriegn units', NL;
}

function demo_function_with_a_return_type(string $some): int {
	return strlen($some);
}

function demo_function_with_callbacks(string $some, callable $success, callable $failure): string {
	$success_callback_result = null;
	if ($success) {
		$success_callback_result = $success('Success!');
	}

	if ($failure) {
		$failure('Some errors.');
	}

	return "the success callback result is: {$success_callback_result}";
}


// program end

# --- generates ---
const __AUTOLOADS = [
	'tea\docs\IDemo' => 'summary.php',
	'tea\docs\IDemoTrait' => 'summary.php',
	'tea\docs\DemoInterface' => 'summary.php',
	'tea\docs\DemoInterfaceTrait' => 'summary.php',
	'tea\docs\DemoBaseClass' => 'summary.php',
	'tea\docs\DemoPublicClass' => 'summary.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
