<?php
namespace docs;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $super_path . 'tea-modules/tea/builtin/__public.php';

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
	'docs\IDemo' => 'dist/summary.php',
	'docs\IDemoTrait' => 'dist/summary.php',
	'docs\DemoInterface' => 'dist/summary.php',
	'docs\DemoInterfaceTrait' => 'dist/summary.php',
	'docs\DemoBaseClass' => 'dist/summary.php',
	'docs\DemoPublicClass' => 'dist/summary.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
