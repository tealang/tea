<?php
namespace tea\docs;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR; // the workspace/vendor path
require_once $super_path . 'tea/builtin/__public.php'; // the builtins

function demo_function2(string $message = 'with a default value') {
	echo 'this function can be called by local or foriegn units', LF;
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

// autoloads
const __AUTOLOADS = [
	'tea\docs\IDemo' => 'dist/summary.php',
	'tea\docs\IDemoTrait' => 'dist/summary.php',
	'tea\docs\DemoInterface' => 'dist/summary.php',
	'tea\docs\DemoInterfaceTrait' => 'dist/summary.php',
	'tea\docs\DemoBaseClass' => 'dist/summary.php',
	'tea\docs\DemoPublicClass' => 'dist/summary.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
