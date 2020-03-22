<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

// ---------
$str = '\'abc123\'';
$match_count = preg_match('/^\\\'[a-z0-9]+\'$/i', $str);
echo $match_count, LF;

$regex = '/[\s\|,]/';
$result0 = regex_match($regex, 'ab cd|e,f');
var_dump($result0);

$result1 = regex_matches($regex, 'ab cd|e,f');
var_dump($result1);

$result2 = preg_split($regex, 'ab cd|e,f');
var_dump($result2);
// ---------

// program end
