<?php
namespace tea\tests\syntax;

require_once dirname(__DIR__, 1) . '/__public.php';

// ---------
$test_items = [];
$test_items['get_key'] = filter_input(INPUT_GET, 'get_key');
$test_items['post_key'] = filter_input(INPUT_POST, 'post_key');
$test_items['cookie_key'] = filter_input(INPUT_COOKIE, 'cookie_key');
$test_items['header_key'] = filter_input(INPUT_SERVER, 'HTTP_HOST');

$items = [];
foreach ($test_items as $k => $v) {
	$items[] = '<li>' . $k . ': ' . ($v === null ? '' : htmlspecialchars($v, ENT_QUOTES)) . '</li>';
}

echo '<ul>
	' . implode(LF, $items) . '
</ul>', LF;
// ---------

// program end
