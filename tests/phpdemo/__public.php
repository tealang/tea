<?php
namespace tests\phpdemo;

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

// program end

// autoloads
const __AUTOLOADS = [
	'tests\phpdemo\PHPClassDemo' => 'Demo.php',
	'tests\phpdemo\BaseInterface' => 'InterfaceDemo.php',
	'tests\phpdemo\Interface1' => 'InterfaceDemo.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require UNIT_PATH . __AUTOLOADS[$class];
});

require_once UNIT_PATH . 'constants.php';
require_once UNIT_PATH . 'functions.php';

// end
