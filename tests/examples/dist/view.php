<?php
namespace tests\examples;

#internal
interface IBaseView {
	public function __construct(string $id = null);
	public function add_subitem($item);
	public function set_attribute(string $key, string $value);
	public function render(): string;
	public function __toString(): string;
}

trait IBaseViewTrait {
	protected $attributes = [];
	protected $subitems = [];

	public function __construct(string $id = null) {
		if (is_string($id)) {
			$this->attributes['id'] = $id;
		}
	}

	public function add_subitem($item) {
		$this->subitems[] = $item;
	}

	public function set_attribute(string $key, string $value) {
		if (!_regex_test('/^[a-z][a-z0-9]*$/i', $key)) {
			throw new \Exception("Invalid key '{$key}'");
		}

		$this->attributes[$key] = $value;
	}

	public function render(): string {
		return '<view ' . \_build_attributes($this->attributes) . '>
	' . _std_join($this->subitems, "\n") . '
</view>';
	}

	public function __toString(): string {
		return $this->render();
	}
}

#public
class ListView implements IBaseView {
	use IBaseViewTrait;

	public function get_subviews() {
		return _std_array_map($this->subitems, function ($item) {
			return '<li>' . \html_escape($item) . '</li>';
		});
	}

	public function render(): string {
		return '<ul ' . \_build_attributes($this->attributes) . '>
	' . _std_join($this->get_subviews(), "\n\t") . '
</ul>';
	}
}

// ---------
$list = new ListView('demo');
$list->add_subitem('A simple text title');
$list->add_subitem('A title has HTML chars <x>');
echo $list, LF;
// ---------

// program end
