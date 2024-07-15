<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class XTag extends BaseExpression
{
	const KIND = 'xtag';

	/**
	 * @var string | PlainIdentifier
	 */
	public $name;
	public $attributes;
	public $children; // text or XTag, let null when tag is self-closed

	public $is_self_closing_tag;
	public $is_literal;

	public function __construct(string $name, array $attributes = [], array $children = null)
	{
		$this->name = $name;
		$this->attributes = $attributes;
		$this->children = $children;
	}
}

class XTagText extends XTag
{
	const KIND = 'xtag_text';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

class XTagComment extends XTag
{
	const KIND = 'xtag_comment';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

// end
