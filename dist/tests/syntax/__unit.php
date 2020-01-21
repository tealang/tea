<?php
namespace tea\tests\syntax;

use tea\tests\xview\{ BaseView, IViewDemo };
use tea\tests\PHPDemoUnit\{ BaseInterface, NS1\Demo as PHPClassDemo, const PHP_CONST_DEMO, function php_function_demo };

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

$super_path = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR; // the workspace/vendor path
require_once $super_path . 'tea/dist/builtin/__unit.php'; // the builtins
require_once $super_path . 'tea/dist/tests/xview/__unit.php';
require_once $super_path . 'tea/tests/PHPDemoUnit/__unit.php';

function fn0($str) {
	echo $str, NL;
}

function xrange(int $start, int $stop, int $step = 1): \Generator {
	$i = null;

	if ($step > 0) {
		$i = $start;
		while ($i < $stop) {
			yield $i;
			$i += $step;
		}

		return;
	}

	if ($step == 0) {
		throw new \Exception('Step should not be 0.');
	}

	$i = $start;
	while ($i > $stop) {
		yield $i;
		$i += $step;
	}
}


// program end

# --- generates ---
const __AUTOLOADS = [
	'tea\tests\syntax\IDemo' => 'class.php',
	'tea\tests\syntax\IDemoTrait' => 'class.php',
	'tea\tests\syntax\BaseClass' => 'class.php',
	'tea\tests\syntax\Test1' => 'class.php',
	'tea\tests\syntax\Test2' => 'class.php',
	'tea\tests\syntax\ITest' => 'class.php',
	'tea\tests\syntax\Test3' => 'class.php',
	'tea\tests\syntax\Test4' => 'class.php',
	'tea\tests\syntax\Test5' => 'class.php',
	'tea\tests\syntax\Data' => 'function.php',
	'tea\tests\syntax\TeaDemoClass' => 'main.php',
	'tea\tests\syntax\CollectorDemo' => 'type-collector.php',
	'tea\tests\syntax\CollectorDemoFactory' => 'type-collector.php',
	'tea\tests\syntax\TestForMetaType0' => 'type-metatype.php',
	'tea\tests\syntax\TestForMetaType1' => 'type-metatype.php',
	'tea\tests\syntax\TestForMetaType2' => 'type-metatype.php',
	'tea\tests\syntax\Cell' => 'type-xview.php',
	'tea\tests\syntax\DemoList' => 'type-xview.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
