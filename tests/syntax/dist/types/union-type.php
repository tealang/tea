<?php
namespace tests\syntax;

require_once dirname(__DIR__, 2) . '/__public.php';

#internal
function test_union_split(string $type_name): string {
	$parts = _std_split($type_name, "|");
	$result = '';
	$i = 0;
	while ($i < count($parts)) {
		$result = $result . $parts[$i] . ", ";
		$i += 1;
	}
	return $result;
}

#internal
function test_string_array_access() {
	$arr = ["Int", "Float", "String"];
	$i = 0;
	while ($i < count($arr)) {
		$item = $arr[$i];
		echo $item, LF;
		$i += 1;
	}
}

#internal
function test_nested_array_access() {
	$types = ["Int|Float", "String|Bool", "Array|Dict"];
	$i = 0;
	while ($i < count($types)) {
		$parts = _std_split($types[$i], "|");
		$j = 0;
		while ($j < count($parts)) {
			echo $parts[$j], LF;
			$j += 1;
		}
		$i += 1;
	}
}

// ---------
echo test_union_split("Int|Float|String"), LF;
test_string_array_access();
test_nested_array_access();
// ---------

// program end
