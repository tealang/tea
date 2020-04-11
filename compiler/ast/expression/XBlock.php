<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class XBlock extends BaseExpression
{
	const KIND = 'xblock';

	public $items;

	public function __construct(XBlockElement ...$items) {
		$this->items = $items;
	}
}

class XBlockElement extends Node
{
	const KIND = 'xblock_element';

	/**
	 * @var string | PlainIdentifier
	 */
	public $name;
	public $attributes;
	public $children; // text or XBlock, let null when item is self-closed

	public $post_spaces; // just for root nodes

	public function __construct($name, array $attributes = [], array $children = null)
	{
		$this->name = $name;
		$this->attributes = $attributes;
		$this->children = $children;
	}
}

class XBlockLeaf extends XBlockElement
{
	const KIND = 'xblock_leaf';

	public function __construct($name, array $attributes = [])
	{
		$this->name = $name;
		$this->attributes = $attributes;
	}
}

class XBlockComment extends XBlockElement
{
	const KIND = 'xblock_comment';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}
