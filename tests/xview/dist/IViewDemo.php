<?php
namespace tests\xview;

#public
interface IViewDemo extends \IView {
	public function set_css_class(string $names);
}

trait IViewDemoTrait {
	public $name = '';
	public $css_class = '';

	public function set_css_class(string $names) {
		$this->css_class = $names;
		return $this;
	}
}

// program end
