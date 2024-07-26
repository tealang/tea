<?php
namespace tests\syntax;

use tests\xview\{ BaseView };

require_once dirname(__DIR__, 2) . '/__public.php';

#internal
class Cell extends BaseView {
	public $text;

	public function __construct(string $text = '') {
		$this->text = $text;
	}

	public function render(): string {
		$text = _std_replace($this->text, LF, '<br>');
		return '<cell>' . $text . '</cell>';
	}
}

#public
class DemoList extends BaseView {
	const ABC = '12';

	public $title;
	public $items;

	public $cells = [];

	public function __construct(string $name, $title = '', array $items = [], ?callable $each = null, callable $error = null) {
		$this->items = $items;

		if ($each) {
			foreach ($items as $item) {
				$cell = $each();
				array_push($this->cells, $cell);
			}
		}

		$error && $error('some error');
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

		return '<view id="' . \htmlspecialchars($this->name) . '">
	' . ($this->title == "abc" ? '<h1>' . \htmlspecialchars($this->title . 123) . '</h1>' : null) . '
	<i></i>
	<cells>
		' . _std_join($cells, LF) . '
	</cells>
	<views>
		' . _std_join($this->subviews, LF) . '
	</views>
	<script> if (a < 1 || a >= 5) {} </script>
</view>';
	}
}

// ---------
$classes = ['big', 'red'];
$form_name = 'form1';
$input_value = 'Hello~';
$view = '<h1 ' . \_build_attributes(['style' => "margin: 10px 0;"], ['class' => _std_join($classes, ' ')]) . '>Form1</h1>
<form name="' . $form_name . '">
	<input value="' . $input_value . '" />
</form>';

$xview = new Cell('string');

new DemoList('demo-list', 'title', [], function () {
	return new Cell();
});

new DemoList('demo-list', 'Demo List', ['A', 'B', 'C'], null, function (string $message) {
	echo $message, LF;
});

$str = 'str';
$num = 2;

$abc = new DemoList('name', 'title', ['A', 'B', 'C'], function () {
	return new Cell();
}, function ($message) use(&$str, &$num) {
	echo $str, $num, LF;
});
// ---------

// program end
