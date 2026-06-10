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

	public string $name;

	/**
	 * fixed attribute map
	 */
	public array $fixed_attributes = [];

	/**
	 * activity attributes expression
	 */
	public ?BaseExpression $dynamic_attributes = null;

	/**
	 * @var XTagElement[]|null
	 */
	public ?array $children = null; // text or XTag, let null when tag is self-closed

	public bool $is_self_closing_tag = false;

	// is there a line break at the inner block opened
	public bool $inner_br = false;

	public ?string $closing_indents = null;

	public function __construct(string $name)
	{
		$this->name = $name;
	}
}

class XTagAttrInterpolation extends BaseExpression implements Interpolation
{
	use InterpolationTrait;
	const KIND = 'xtag_attr_interpolation';
}

class XTagChildInterpolation extends XTagElement implements Interpolation
{
	use InterpolationTrait;
	const KIND = 'xtag_child_interpolation';
}

class XTagText extends XTagElement
{
	const KIND = 'xtag_text';

	public string $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

class XTagComment extends XTagElement
{
	const KIND = 'xtag_comment';

	public string $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

// end
