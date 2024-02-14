<?php
namespace tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

// ---------
$str = ' a string';

echo 'abc', 'efg', '123', LF;

print('abc' . $str);

echo LF;
// ---------

// program end
