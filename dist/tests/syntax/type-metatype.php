<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

#internal
class TestForMetaType0 {
	public $prop = 'some';

	public static function test_class_argument(string $class) {
		var_dump($class);
	}

	public static function test_array_argument(array $array111 = [1]) {
		var_dump($array111);
	}
}

#internal
class TestForMetaType1 extends TestForMetaType0 {
	// no any
}

#internal
class TestForMetaType2 {
	// no any
}

// ---------
TestForMetaType0::test_class_argument(TestForMetaType0::class);
TestForMetaType0::test_class_argument(TestForMetaType1::class);

TestForMetaType0::test_array_argument([]);

$type = TestForMetaType0::class;
var_dump($type);
// ---------

// program end
