<?php
namespace tea\tests\xview;

#public
interface IViewDemo extends \IView
{
	public function set_css_class(string $names): IViewDemo;
}

trait IViewDemoTrait
{
	public $name = '';
	public $css_class = '';

	public function set_css_class(string $names): IViewDemo
	{
		$this->css_class = $names;
		return $this;
	}
}

// program end
