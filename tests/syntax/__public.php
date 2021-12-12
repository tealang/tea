<?php
namespace tea\tests\syntax;

use tea\tests\PHPDemoUnit\{ const PHP_CONST_DEMO };

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR; // the workspace/vendor path
require_once $super_path . 'tea/builtin/__public.php'; // the builtins
require_once $super_path . 'tea/tests/xview/__public.php';
require_once $super_path . 'tea/tests/PHPDemoUnit/__public.php';

function fn0($str) {
	echo $str, LF;
	return PHP_CONST_DEMO;
}


// program end

// autoloads
const __AUTOLOADS = [
	'tea\tests\syntax\PHPClassInMixed1' => '_mixed1.php',
	'tea\tests\syntax\IDemo' => 'dist/class.php',
	'tea\tests\syntax\IDemoTrait' => 'dist/class.php',
	'tea\tests\syntax\IterableObject' => 'dist/class.php',
	'tea\tests\syntax\Test1' => 'dist/class.php',
	'tea\tests\syntax\Test2' => 'dist/class.php',
	'tea\tests\syntax\ITest' => 'dist/class.php',
	'tea\tests\syntax\Test3' => 'dist/class.php',
	'tea\tests\syntax\Test4' => 'dist/class.php',
	'tea\tests\syntax\Test5' => 'dist/class.php',
	'tea\tests\syntax\Data' => 'dist/function.php',
	'tea\tests\syntax\TeaDemoClass' => 'dist/main.php',
	'tea\tests\syntax\CollectorDemo' => 'dist/types/collector.php',
	'tea\tests\syntax\CollectorDemoFactory' => 'dist/types/collector.php',
	'tea\tests\syntax\TestForMetaType0' => 'dist/types/metatype.php',
	'tea\tests\syntax\TestForMetaType1' => 'dist/types/metatype.php',
	'tea\tests\syntax\TestForMetaType2' => 'dist/types/metatype.php',
	'tea\tests\syntax\Cell' => 'dist/types/xview.php',
	'tea\tests\syntax\DemoList' => 'dist/types/xview.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
