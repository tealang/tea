<?php
namespace tests\xview;

#public
abstract class BaseView implements \IView {
	public $name;

	protected $props = [];
	protected $subviews = [];

	public function __construct(array $props = null) {
		if (is_array($props)) {
			$this->props = $props;
		}
	}

	public function prop(string $key, $value): BaseView {
		$this->props[$key] = $value;
		return $this;
	}

	public function subview(string $view): BaseView {
		$this->subviews[] = $view;
		return $this;
	}

	public function render(): string {
		return '<xview ' . \_build_attributes($this->props) . '>' . _std_join($this->subviews, LF) . '</xview>';
	}

	public function __tostring(): string {
		return $this->render();
	}
}

// program end
