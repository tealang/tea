<?php
namespace tea\tests\xview;

#public
class BaseView implements \IView
{
	public $name;

	protected $props = [];
	protected $subviews = [];

	public function __construct(array $props = null)
	{
		if ($props) {
			$this->props = $props;
		}
	}

	public function prop(string $key, $value): BaseView
	{
		$this->props[$key] = $value;
		return $this;
	}

	public function subview(string $view): BaseView
	{
		$this->subviews[] = $view;
		return $this;
	}

	protected function build_props(): string
	{
		$props = '';
		foreach ($this->props as $key => $value) {
			$props .= ' ' . htmlspecialchars($key, ENT_QUOTES) . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
		}

		return $props;
	}

	public function render(): string
	{
		return '<xview' . $this->build_props() . '>' . implode($this->subviews, NL) . '</xview>';
	}

	public function __toString(): string
	{
		return $this->render();
	}
}

// program end
