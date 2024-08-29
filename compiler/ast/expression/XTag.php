<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class XTagElement extends BaseExpression
{
	//
}

class XTag extends XTagElement
{
	const KIND = 'xtag';

	/**
	 * @var string
	 */
	public $name;

	/**
	 * fixed attribute map
	 * @array
	 */
	public $fixed_attributes = [];

	/**
	 * activity attributes expression
	 * @BaseExpression
	 */
	public $dynamic_attributes;

	/**
	 * @var XTagElement[]
	 */
	public $children; // text or XTag, let null when tag is self-closed

	public $is_self_closing_tag;

	// is there a line break at the inner block opened
	public $inner_br = false;

	public $closing_indents;

	public function __construct(string $name)
	{
		$this->name = $name;
	}
}

class XTagAttrInterpolation extends BaseExpression
{
	use InterpolationTrait;
	const KIND = 'xtag_attr_interpolation';
}

class XTagChildInterpolation extends XTagElement
{
	use InterpolationTrait;
	const KIND = 'xtag_child_interpolation';
}

class XTagText extends XTagElement
{
	const KIND = 'xtag_text';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

class XTagComment extends XTagElement
{
	const KIND = 'xtag_comment';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

// end
