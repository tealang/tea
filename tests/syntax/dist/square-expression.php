<?php
namespace tea\tests\syntax;

// ---------
$items = ['a', 'b'];

$items[] = 'c';

var_dump($items);

$last_element = array_pop($items);

var_dump($last_element);

array_unshift($items, 'd');

var_dump($items);

$first_element = array_shift($items);

var_dump($first_element, $items);
// ---------

// program end
