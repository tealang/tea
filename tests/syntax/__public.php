<?php
namespace tests\syntax;

use tests\phpdemo\{ const PHP_CONST_DEMO };

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $super_path . 'tea-modules/tea/builtin/__public.php';
require_once $super_path . 'tests/xview/__public.php';
require_once $super_path . 'tests/phpdemo/__public.php';

function fn0($str) {
	echo $str, LF;
	return PHP_CONST_DEMO;
}


// program end

// autoloads
const __AUTOLOADS = [
	'tests\syntax\PHPClassInMixed1' => '_mixed1.php',
	'tests\syntax\IDemo' => 'dist/class.php',
	'tests\syntax\IDemoTrait' => 'dist/class.php',
	'tests\syntax\IterableObject' => 'dist/class.php',
	'tests\syntax\Test1' => 'dist/class.php',
	'tests\syntax\Test2' => 'dist/class.php',
	'tests\syntax\ITest' => 'dist/class.php',
	'tests\syntax\Test3' => 'dist/class.php',
	'tests\syntax\Test4' => 'dist/class.php',
	'tests\syntax\Test5' => 'dist/class.php',
	'tests\syntax\Data' => 'dist/function.php',
	'tests\syntax\TeaDemoClass' => 'dist/main.php',
	'tests\syntax\TestForMetaType0' => 'dist/types/metatype.php',
	'tests\syntax\TestForMetaType1' => 'dist/types/metatype.php',
	'tests\syntax\TestForMetaType2' => 'dist/types/metatype.php',
	'tests\syntax\Cell' => 'dist/types/xview.php',
	'tests\syntax\DemoList' => 'dist/types/xview.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
