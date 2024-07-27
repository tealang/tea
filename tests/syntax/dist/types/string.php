<?php
namespace tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
$num = 123;
$str = "hello ";
$key = "f3";

echo mb_strlen("some string"), LF;

echo trim($str), LF;

substr($str, 2);
mb_substr($str, 0, 3);

$arr = [
	'f1' => 123,
	"f2" => "hi",
	"f3" => "字符串",
	"sub" => [1, 2, 3]
];

echo "{$str}world", LF;
echo $str . '\n
	- ' . $arr['f1'] . '
	- ' . $arr[$key] . '
', LF;

echo "\$str world", LF;
echo '
	- ${arr[\'f1\']}
	- ${arr[key]}
', LF;

$abc = 'hhh';

'string' . $abc;
"string{$abc}";

html_escape("string{$abc}\n");
html_escape('string');
html_escape('string<br>' . $abc);

$v_10 = "sth.";
$v_11 = 123;

$v_20 = null;
$v_21 = "sth.";
// ---------

// program end
