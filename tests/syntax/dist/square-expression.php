<?php
namespace tea\tests\syntax;

// ---------
$list = ['a', 'b'];

$list[] = 'c';

var_dump($list);

$last_element = array_pop($list);

var_dump($last_element);
// ---------

// program end
