<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

// ---------
echo NL;

$str = ' a string';

echo 'abc', 'efg', '123', NL;

echo 'abc', $str;
// ---------

// program end
