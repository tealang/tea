<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__public.php';

// ---------
$num = 123;
$str = "hello ";
$key = "f3";

echo strlen("some string"), LF;

$str = trim($str);

echo $str, LF;

substr($str, 2);
substr($str, 0, 3);

$arr = [
	'f1' => 123,
	"f2" => "hi",
	"f3" => "字符串",
	"sub" => [1, 2, 3]
];

echo ($str . 'abc') . " world", LF;
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

$var1 = '<div>' . '<script>' . '</div>';

'string' . $abc;
"string{$abc}";
'string' . htmlspecialchars($abc, ENT_QUOTES);

'string${abc}';
"string\${abc}";

html_encode("string{$abc}\n");
html_encode('string');
html_encode('string<br>' . $abc);

'A' . $abc . 'BC\n';

$v_10 = "sth.";
$v_11 = 123;

$v_20 = null;
$v_21 = "sth.";
// ---------

// program end
