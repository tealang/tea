<?php
namespace tests\xview;

#public
interface PureInterface {
	public const ABC = 'abc';
	public function set_css_class(string $names);
}

#public
interface IViewDemo extends \IView {
	public function set_css_class(string $names);
}

trait IViewDemo_T {
	protected const ABC = 'abc';
	public $name = '';
	public $css_class = '';

	public function set_css_class(string $names) {
		$this->css_class = $names;
		return $this;
	}

	protected abstract function set_some(string $some);
}

// program end
