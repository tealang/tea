<?php
namespace tea\tests\xview;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

// program end

// autoloads
const __AUTOLOADS = [
	'tea\tests\xview\BaseView' => 'dist/BaseView.php',
	'tea\tests\xview\IViewDemo' => 'dist/IViewDemo.php',
	'tea\tests\xview\IViewDemoTrait' => 'dist/IViewDemo.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
