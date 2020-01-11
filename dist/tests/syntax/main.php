<?php
namespace tea\tests\syntax;

use tea\tests\PHPDemoUnit\{ NS1\Demo as PHPClassDemo, function php_function_demo, const PHP_CONST_DEMO };

require_once __DIR__ . '/__unit.php';

#internal
class TeaDemoClass {
	public static $static_prop1 = 'static prop value';

	public $prop1 = 'prop1 value';

	public static function static_method(): string {
		return TeaDemoClass::$static_prop1;
	}

	public function method1(string $param1): string {
		return $this->prop1;
	}

	public function method2(): string {
		return 'some';
	}
}

// ---------
echo "\n***Test for class call:", NL;
$php_class_demo_object = new PHPClassDemo();
echo $php_class_demo_object->get_class_name('main1'), NL;
$methods = $php_class_demo_object::get_target_class_methods(TeaDemoClass::class);
var_dump($methods);

php_function_demo('hei~');
var_dump(PHP_CONST_DEMO);

echo "\n***Test for range:", NL;
$items = range(0, 9, 2);
var_dump($items);

echo "\n***Test for include:", NL;
$title = 'include from main1.tea';
$result = (include UNIT_PATH . 'label-expect.php');
echo $result, NL;

echo "\n***Test for int/uint convert:", NL;
echo "'-123abc' convert to int: ", intval('-123abc'), NL;
echo "'-123abc' convert to uint: ", uintval('-123abc'), NL;
// ---------

// program end
