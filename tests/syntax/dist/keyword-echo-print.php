<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__public.php';

// ---------
echo LF;

$str = ' a string';

echo 'abc', 'efg', '123', LF;

echo 'abc', $str;
// ---------

// program end
