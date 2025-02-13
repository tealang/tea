<?php
namespace tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
$object = (object)['prop1' => 'prop value1', 'prop2' => 123, 'prop3' => [1, 2, 3], 'func' => function (string $message) {
	return 'returned "' . $message . '" in closure of object-member "func"';
}];

$object->abc = 123;

var_dump($object->func('some ...'));
var_dump($object);
// ---------

// program end
