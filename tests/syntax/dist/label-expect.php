<?php
namespace tea\tests\syntax;

#expect string $title, array $items;

// ---------
if (!$items) {
	return "There no items for title: {$title}\n";
}

$list = [];
foreach ($items as $k => $v) {
	array_push($list, '<li>' . $k . ': ' . $v . '</li>');
}

return '<section>
	<h1>' . $title . '</h1>
	<ul>
		' . implode(LF, $list) . '
	</ul>
</section>';
// ---------

// program end
