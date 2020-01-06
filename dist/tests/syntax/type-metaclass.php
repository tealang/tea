<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

#internal
class TestForMetaClass0 {
	public $prop = 'some';

	public static function test_class_argument(string $class) {
		var_dump($class);
	}
}

#internal
class TestForMetaClass1 {
	// no any
}

// ---------
TestForMetaClass0::test_class_argument(TestForMetaClass0::class);
// ---------

// program end
