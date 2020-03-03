<?php
namespace tea\tests\xview;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

// program end

# --- generates ---
const __AUTOLOADS = [
	'tea\tests\xview\BaseView' => 'BaseView.php',
	'tea\tests\xview\IViewDemo' => 'IViewDemo.php',
	'tea\tests\xview\IViewDemoTrait' => 'IViewDemo.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
