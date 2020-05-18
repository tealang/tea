<?php
namespace tea\tests\syntax;

use tea\tests\PHPDemoUnit\{ NS1\Demo as PHPClassDemo, function php_function_demo, const PHP_CONST_DEMO };

require_once dirname(__DIR__, 1) . '/__public.php';

require_once UNIT_PATH . '_mixed2.php';

#internal
class TeaDemoClass {
	public static $static_prop1 = 'static prop value';

	public $prop1 = 'prop1 value';

	public static function static_method(array $some): string {
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
$caller = 'The caller in main.tea';

echo "\n***Test for call with mixed programming:", LF;
$result1 = (new PHPClassInMixed1($caller))->get_message();
$result2 = php_get_num() + 1;
var_dump($result1, $result2);

echo "\n***Test for class call:", LF;
$php_class_demo_object = new PHPClassDemo();
echo $php_class_demo_object->get_class_name('main1'), LF;
$methods = $php_class_demo_object::get_target_class_methods(TeaDemoClass::class);
var_dump($methods);

php_function_demo('hei~');
var_dump(PHP_CONST_DEMO);

echo "\n***Test for range:", LF;
$items = range(0, 9, 2);
var_dump($items);

echo "\n***Test for include:", LF;
$title = 'include from main1.tea';
$result = (include UNIT_PATH . 'dist/expect.php');
var_dump($result);

echo "\n***Test for int/uint cast:", LF;
echo "'-123abc' cast to int: ", (int)'-123abc', LF;
// ---------

\Swoole\Event::wait();

// program end
