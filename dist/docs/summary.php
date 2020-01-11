<?php
namespace tea\docs;

use Exception;
use ErrorException;

require_once __DIR__ . '/__unit.php';

#public
interface IDemo {
	const CONST1 = 'This is a constant!';
	public static function say_hello_with_static(string $name = 'Benny');
}

trait IDemoTrait {
	public static $a_static_prop = "a static property.";

	public static function say_hello_with_static(string $name = 'Benny') {
		echo "Hello, {$name}\n";
	}
}

#internal
interface DemoInterface {
	public function set_message(string $message);
	public function get_message(): string;
}

trait DemoInterfaceTrait {
	public $message = 'hei~';

	public function get_message(): string {
		return $this->message;
	}
}

#internal
class DemoBaseClass {
	public function __construct(string $name) {
		echo "Hey, {$name}, it is constructing...", NL;
	}

	public function __destruct() {
		echo "it is destructing...", NL;
	}

	protected function a_protected_method() {
		// no any
	}
}

#public
class DemoPublicClass extends DemoBaseClass implements IDemo, DemoInterface {
	use IDemoTrait, DemoInterfaceTrait;

	public function set_message(string $message) {
		$this->message = $message;
	}
}

// ---------
echo "世界你好！", NL;

echo 'Hey,', 'Who are you?', NL;

echo 'string1', 'string2', NL;

$any = null;
$any = 1;
$any = [];
$any = 'abc';

$str_from_any = (string)$any;

$str = 'Unescaped string\n';
$str = "Escaped string\n";
$str_with_interpolation = 'Unescaped string with interpolation ' . (5 * 6);
$str_with_interpolation = "Escaped string with interpolation " . (5 * 6) . "\n";

$xss = '<script>alert("XSS!")</script>';
$html_escaped_interpolation = "The html-escaped string: " . htmlspecialchars($xss, ENT_QUOTES);
$text_labeled = "would not process interpolation \${5 * 6}";

strlen($str);
iconv_strlen($str);
substr($str, 0, 3);
iconv_substr($str, 0, 3);

$uint_num = 123;
$int_num = -123;
$float_num = 123.01;
$bool = true;

$xview = '<div>
	<h1>XView是什么？</h1>
	<p>XView类似字符串，但无需引号，可以直接按HTML标签方式编写</p>
	<p>Interpolation with origin ' . ($uint_num * 10) . '</p>
	<p>Interpolation with html-escaped ' . htmlspecialchars($xss, ENT_QUOTES) . '</p>
</div>';

$regex = '/^[a-z0-9\'_"]+$/i';
if (regex_match($regex, 'Abc\'123"') !== null) {
	echo 'matched!', NL;
}

$any_array = [
	123,
	'Hi',
	false,
	[1, 2, 3]
];

$int_array = [];
$int_array = [-1, 10, 200];
count($int_array);
array_slice($int_array, 0, 2);

$str_dict = [];
$str_dict = [
	'k1' => 'value for string key "k1"',
	123 => 'value for int key "123"'
];

$str_dict_array = [
	['k0' => 'v0', 'k1' => 'v01'],
	$str_dict
];

$str1 = 'Hi!';

$str2 = null;

$var_without_decared = 123;

$pow_result = ((-2) ** 3) ** 5;

$string_concat = 'abc' . (1 + (8 & 2) * 3);
$array_concat = array_merge(['A', 'B'], ['A1', 'C1']);

$array_merge = array_replace(['A', 'B'], ['A1', 'B1']);
$dict_merge = array_replace(['a' => 'A', 'b' => 'B'], ['a' => 'A1', 'c' => 'C1']);

$int_from_string = (int)'123';
$uint_from_string = uint_ensure((int)'-123');
$str_from_uint = (string)123;
$str_from_int = (string)-123;
$str_from_float = (string)123.123;

$ex1 = null;
$ex2 = $ex1;

is_int(1.1);
is_int(1);
is_uint(2);
new \ErrorException('Some') instanceof Exception;

$not_result = !($uint_num > 3);

$ternary_result = $uint_num == 1 ? 'one' : ($uint_num == 2 ? 'two' : ($uint_num == 3 ? 'three' : 'other'));

$a = 0;
$b = 1;

try {
	if ($a) {
		// no any
	}
	elseif ($b) {
		// no any
	}
	else {
		// no any
	}
}
catch (\ErrorException $ex) {
	// no any
}
catch (\Exception $ex) {
	// no any
}
finally {
	// no any
}

if ($str_dict && count($str_dict) > 0) {
	foreach ($str_dict as $k => $v) {
		// no any
	}
}
else {
	echo 'dict is empty', NL;
}

if (0 <= 9) {
	for ($i = 0; $i <= 9; $i += 1) {
		// no any
	}
}
else {
	// no any
}

for ($i = 9; $i >= 0; $i -= 2) {
	// no any
}

$i = 0;
try {
	while (1) {
		while (true) {
			$i = $i + 1;
			if ($i > 10) {
				break 2;
			}
			else {
				continue 1;
			}
		}
	}
}
catch (\Exception $ex) {
	// no any
}

$ret1 = demo_function_with_a_return_type('some data');

$ret2 = demo_function_with_callbacks('some data', function ($message) {
	echo $message, NL;
	return 'some return data';
}, function ($error) {
	echo $error, NL;
});

$object = new DemoPublicClass('Benny');

$object->set_message('some string');

$object::say_hello_with_static();
DemoPublicClass::say_hello_with_static();
// ---------

// program end
