<?php
namespace tea\tests\syntax;

use tea\tests\xview\{ BaseView };

\Swoole\Runtime::enableCoroutine();

require_once dirname(__DIR__, 2) . '/__public.php';

#internal
class Cell extends BaseView {
	public $text;

	public function __construct(string $text = '') {
		$this->text = $text;
	}

	public function render(): string {
		$text = _str_replace($this->text, LF, '<br>');
		return '<cell>' . $text . '</cell>';
	}
}

#public
class DemoList extends BaseView {
	const ABC = '12';

	public $title;
	public $items;

	public $cells = [];

	public function __construct(string $name, string $title = '', array $items = [], callable $each = null, callable $error = null) {
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

		return '<view id="' . $this->name . '">
	' . ($this->title == "abc" ? '<h1>' . htmlspecialchars($this->title . 123, ENT_QUOTES) . '</h1>' : null) . '
	<i></i>
	<cells>
		' . implode(LF, $cells) . '
	</cells>
	<views>
		' . implode(LF, $this->subviews) . '
	</views>
	<script> if (a < 1 || a >= 5) {} </script>
</view>';
	}
}

// ---------
$xview = new Cell('string');

new DemoList('demo-list', 'title', [], function () {
	return new Cell();
});

new DemoList('demo-list', 'Demo List', ['A', 'B', 'C'], null, function (string $message) {
	echo $message, LF;
});

$str = 'str';
$num = 2;

$abc = new DemoList('', '', ['A', 'B', 'C'], function () {
	return new Cell();
}, function ($message) use($str, $num) {
	echo $str, $num, LF;
});
// ---------

\Swoole\Event::wait();

// program end
