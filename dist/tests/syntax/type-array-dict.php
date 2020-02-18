<?php
namespace tea\tests\syntax;

require_once __DIR__ . '/__unit.php';

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

echo array_pop($arr), LF;

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
	echo '<span>' . $key . ': ' . $val . '</span>', LF;
}

$users = [
	["id" => 1, "name" => "name1"],
	["id" => 2, "name" => "name2"],
	["id" => 3, "name" => "name3"]
];

$id2name = [];
foreach ($users as $user) {
	$id2name[(string)$user['id']] = $user['name'];
}

$mapped = array_map(function ($item) {
	return $item['id'] . ' - ' . $item['name'];
}, $users);
var_dump($mapped);

$reduced = array_reduce([1, 2, 3], function ($carry, $item) {
	return (int)$carry + (int)$item;
}, 10);
var_dump($reduced);

$filtered = array_filter([0, 1, 2, 3, 4, 5], function ($item) {
	if ((int)$item % 2 == 0) {
		return true;
	}
});
var_dump($filtered);
// ---------

// program end
