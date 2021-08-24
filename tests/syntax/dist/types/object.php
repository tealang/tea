<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

// ---------
$object = (object)['prop1' => 'prop value', 'prop2' => 123, 'prop3' => [1, 2, 3]];

var_dump($object);
// ---------

// program end
