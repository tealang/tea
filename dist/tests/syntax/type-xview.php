<?php
namespace tea\tests\syntax;

use tea\tests\xview\{ BaseView };

require_once __DIR__ . '/__unit.php';

#internal
class Cell extends BaseView {
	public $text;

	public function __construct(string $text = '') {
		$this->text = $text;
	}

	public function render(): string {
		$text = _str_replace($this->text, NL, '<br>');
		return '<cell>' . $text . '</cell>';
	}
}

#public
class DemoList extends BaseView {
	const ABC = '12';

	public $tag;
	public $title;
	public $items;

	public $cells = [];

	public function __construct(string $name, string $title = '', array $items = [], callable $each = null, callable $done = null) {
		$cell = null;

		$this->items = $items;

		if ($each) {
			foreach ($items as $item) {
				$cell = $each((string)$item);
				array_push($this->cells, $cell);
			}
		}

		$done && $done();
	}

	public function render(): string {
		$cells = [];
		foreach ($this->items as $key => $value) {
			if (1) {
				array_push($cells, '<li index="0"> ' . $key . ': ' . $value . ' </li>');
			}
			else {
				array_push($cells, '<li> ' . $key . ': ' . $value . ' </li>');
			}
		}

		return '<' . $this->tag . ' id="' . $this->name . '">
	' . ($this->title == "abc" ? '<h1>' . htmlspecialchars($this->title . 123, ENT_QUOTES) . '</h1>' : null) . '
	<i></i>
	<cells>
		' . implode(NL, $cells) . '
	</cells>
	<views>
		' . implode(NL, $this->subviews) . '
	</views>
	<script> if (a < 1 || a >= 5) {} </script>
</' . $this->tag . '>';
	}
}

// ---------
$xview = new Cell('string');

new DemoList('demo-list', 'title', [], function () {
	return new Cell();
});

new DemoList('demo-list', 'Demo List', ['A', 'B', 'C'], function (string $item) {
	return new Cell($item);
});

$str = 'str';
$num = 2;

$abc = new DemoList('', '', ['A', 'B', 'C'], function ($item) {
	return new Cell((string)$item);
}, function () use(&$str, &$num) {
	echo $str, $num, NL;
});

echo $abc, NL;
// ---------

// program end
