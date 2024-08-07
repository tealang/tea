<?php
namespace tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
$str = '\'abc123\'';
$match_count = preg_match('/^\\\'[a-z0-9]+\'$/i', $str);
echo $match_count, LF;

$regex = '/[\s\|,]/';
$result0 = _regex_capture($regex, 'ab cd|e,f');
var_dump($result0);

$result1 = _regex_capture_all($regex, 'ab cd|e,f');
var_dump($result1);

$result2 = preg_split($regex, 'ab cd|e,f');
var_dump($result2);

$is_matched = _regex_test('/^a-z$/i', 'abcABC');
var_dump($is_matched);
// ---------

// program end
