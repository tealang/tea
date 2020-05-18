<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

#internal
class TestForMetaType0 {
	public $prop = 'some';
	const TRUE = 1;

	public static function test_class_argument(string $class) {
		var_dump($class);
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
$string_type = 'String';
echo $string_type, LF;

$class_type = TestForMetaType0::class;
TestForMetaType0::test_class_argument($class_type);
TestForMetaType0::test_class_argument(TestForMetaType1::class);
// ---------

\Swoole\Event::wait();

// program end
