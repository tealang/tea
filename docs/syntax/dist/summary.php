<?php
namespace docs\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

function fn0($str) {
	echo $str, LF;
}

function fn1(callable $callee) {
	$callee('test call for the Callable argument');
}

function demo_function1(string $message) {
	echo 'this function can only be called by local unit', LF;
	return function (int $a) {
		echo 'the number is ' . $a, LF;
	};
}

#public
interface IDemo {
	const CONST1 = 'This is a constant!';
	public static function say_hello_with_static(string $name = 'Benny');
}

trait IDemoTrait {
	public static $a_static_prop = "a static property.";

	public static function say_hello_with_static(string $name = 'Benny') {
		echo "Hello, {$name}", LF;
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
		echo "Hey, {$name}, it is constructing...", LF;
	}

	public function __destruct() {
		echo "it is destructing...", LF;
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
echo "Hello, 世界", LF;

echo 'Hello, ', '世界', LF;

print('Hello, 世界');

echo LF;

$any = null;
$any = 1;
$any = [];
$any = 'abc          ';

$valid_len = strlen(trim($any));
echo 'the valid strlen is: ' . $valid_len, LF;
echo 'use Pipe Call to explode strings, then get count: ' . count(explode('|', 'a|b|c')), LF;

$any_as_string = (string)$any;

$str = 'Unescaped string\n';

$str = "Escaped string\n";

$str_with_interpolation = 'Unescaped string with interpolation ' . (5 * 6);

$str_with_interpolation = "Escaped string with interpolation " . (5 * 6) . "\n";

$safe_html = '<strong>Some strong text.</strong>';
$unsafe_html = '<script>alert("XSS!")</script>';
$html_escaped_interpolation = "html-unescaped: {$safe_html}. html-escaped: " . \html_encode($unsafe_html);

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
	<p>Interpolation with origin ' . $uint_num * 10 . '</p>
	<p>Interpolation with html-escaped ' . \html_encode($unsafe_html) . '</p>
</div>';

$regex = '/^[a-z0-9\'_"]+$/i';
if (regex_capture($regex, 'Abc\'123"') !== null) {
	echo 'captured!', LF;
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

$pow_result = ((-2) ** 2) ** 3;

$string_concat = 'abc' . (1 + (8 & 2) * 3);
$array_concat = array_merge(['A', 'B'], ['A1', 'C1']);

$uint_from_non_negative_string = uint_ensure((int)'123');
$str_from_uint = (string)123;
$str_from_float = (string)123.123;

$ex1 = null;
$ex2 = $ex1;

is_int(1.1);
is_int(1);
is_uint(2);
new \ErrorException('Some') instanceof \Exception;

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
	echo 'dict is empty', LF;
}

if (0 <= 9) {
	foreach (\xrange(0, 9) as $i) {
		// no any
	}
}
else {
	// no any
}

foreach (\xrange(9, 0, -2) as $i) {
	// no any
}

$i = 0;
try {
	while (1) {
		while (true) {
			$i += 1;
		}
	}
}
catch (\Exception $ex) {
	// no any
}

fn1('docs\syntax\fn0');

demo_function1('hei')(123);

$ret1 = demo_function_with_a_return_type('some data');

$ret2 = demo_function_with_callbacks('some data', function ($message) {
	echo $message, LF;
	return 'some return data';
}, function ($error) {
	echo $error, LF;
});

$object = new DemoPublicClass('Benny');

$object->set_message('some string');

$object::say_hello_with_static();
DemoPublicClass::say_hello_with_static();
// ---------

// program end
