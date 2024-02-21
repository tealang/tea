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

	protected function build_props() {
		$props = '';
		foreach ($this->props as $key => $value) {
			$props .= ' ' . \html_encode($key) . '="' . \html_encode($value) . '"';
		}

		return $props;
	}

	public function render(): string {
		return '<xview' . $this->build_props() . '>' . implode(LF, $this->subviews) . '</xview>';
	}

	public function __toString(): string {
		return $this->render();
	}
}

// program end
