<?php
namespace tea\tests\syntax;

// ---------
$str_dict_list = [['a' => 'abc', 'b' => '123.1']];
$int_dict = [];

$str_list = [];
$float_dict_array = [];

$str_list[] = 'str';

$int_dict['k2'] = -123;

$float_dict_array[] = ['abc' => 123.12];

$arr = [];
array_push($arr, [1, 2, 3]);
array_push($arr, "hello");

echo array_pop($arr), NL;
echo implode($arr, NL), NL;

$arr1 = [1, 2, 3];
$arr2 = [4, 5, 6];
$arr3 = array_merge($arr1, $arr2);

$dict = [];
$dict[(string)1.1] = 1;

$key = 1;
$key1 = 1;
$key2 = 1.1;
$key3 = false;

$dict1 = [
	$key => "abc",
	(string)$key1 => "abc",
	(string)$key3 => "abc",
	"k1" => "v1"
];

$dict2 = [
	(string)$key2 => "abc",
	"k1" => "v1"
];

foreach ($dict1 as $key => $val) {
	echo '<span>' . $key . ': ' . $val . '</span>', NL;
}

$students = [
	["name" => "name1", "class" => 1],
	["name" => "name2", "class" => 2],
	["name" => "name3", "class" => 3]
];

$students_by_class = [];
foreach ($students as $student) {
	$students_by_class[(string)$student['class']] = $student;
}
// ---------

// program end
