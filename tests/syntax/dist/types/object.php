<?php
namespace tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
$object = (object)['prop1' => 'prop value1', 'prop2' => 123, 'prop3' => [1, 2, 3], 'func' => function () {
	return 'returned in closure of object-member "func"';
}, public $abc;];

$object->abc = 123;

var_dump(($object->func)());
var_dump($object);
// ---------

// program end
