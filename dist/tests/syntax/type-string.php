<?php
namespace tea\tests\syntax;

// ---------
$num = 123;
$str = "hello ";
$key = "f3";

$str = trim($str);

echo $str, NL;

substr($str, 2);
iconv_substr($str, 0, 3);

$arr = [
	'f1' => 123,
	"f2" => "hi",
	"f3" => "字符串",
	"sub" => [1, 2, 3]
];

echo ($str . 'abc') . " world", NL;
echo $str . '\n
	- ' . $arr['f1'] . '
	- ' . $arr[$key] . '
', NL;

echo "\$str world", NL;
echo '
	- ${arr[\'f1\']}
	- ${arr[key]}
', NL;

$abc = 'hhh';

$var1 = '<div>' . '<script>' . '</div>';

'string' . $abc;
"string{$abc}";
'string' . htmlspecialchars($abc, ENT_QUOTES);

'string${abc}';
"string\${abc}";

htmlspecialchars("string{$abc}\n", ENT_QUOTES);
htmlspecialchars('string', ENT_QUOTES);
htmlspecialchars('string<br>' . $abc, ENT_QUOTES);

'A' . $abc . 'BC\n';

$v_10 = "sth.";
$v_11 = 123;

$v_20 = null;
$v_21 = "sth.";
// ---------

// program end
