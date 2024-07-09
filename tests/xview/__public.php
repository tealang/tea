<?php
namespace tests\xview;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

// program end

// autoloads
const __AUTOLOADS = [
	'tests\xview\BaseView' => 'dist/BaseView.php',
	'tests\xview\PureInterface' => 'dist/IViewDemo.php',
	'tests\xview\IViewDemo' => 'dist/IViewDemo.php',
	'tests\xview\IViewDemoTrait' => 'dist/IViewDemo.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

// end
