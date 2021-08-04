<?php
namespace tea\examples;

#internal
interface IBaseView {
	public function __construct(string $id = null);
	public function add_subitem($item);
	public function set_attribute(string $key, string $value);
	public function build_attributes();
	public function render(): string;
	public function __toString(): string;
}

trait IBaseViewTrait {
	protected $attributes = [];
	protected $subitems = [];

	public function __construct(string $id = null) {
		if ($id !== null) {
			$this->attributes['id'] = $id;
		}
	}

	public function add_subitem($item) {
		$this->subitems[] = $item;
	}

	public function set_attribute(string $key, string $value) {
		if (!regex_test('/^[a-z][a-z0-9]*$/i', $key)) {
			throw new \Exception("Invalid key '{$key}'");
		}

		$this->attributes[$key] = $value;
	}

	public function build_attributes() {
		$items = [];
		foreach ($this->attributes as $key => $value) {
			$items[] = $key . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
		}

		return implode("\n", $items);
	}

	public function render(): string {
		return '<view ' . $this->build_attributes() . '>
	' . implode("\n", $this->subitems) . '
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
		return array_map(function ($item) {
			return '<li>' . htmlspecialchars($item, ENT_QUOTES) . '</li>';
		}, $this->subitems);
	}

	public function render(): string {
		return '<ul ' . $this->build_attributes() . '>
	' . implode("\n\t", $this->get_subviews()) . '
</ul>';
	}
}

// ---------
$list = new ListView('demo');
$list->add_subitem('A simple text title');
$list->add_subitem('A title has HTML chars <x>');
echo $list;
// ---------

// program end
