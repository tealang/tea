<?php
namespace tea\tests\syntax;

#expect string $title, array $items;

// ---------
$list = [];
foreach ($items as $k => $v) {
	array_push($list, '<li>' . $k . ': ' . $v . '</li>');
}

return '<section>
	<h1>' . $title . '</h1>
	<ul>
		' . implode(NL, $list) . '
	</ul>
</section>';
// ---------

// program end
