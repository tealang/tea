<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class Node
{
	const KIND = null;

	public $pos; 	// the first token position of current node

	// is there a line break at the head
	public $leading_br = false;

	// is there a line break at the end
	public $tailing_br = false;

	/**
	 * @var DocComment
	 */
	public $doc;

	// tailing inline comment
	public $tailing_comment;

	public $indents;

	public function render(BaseCoder $coder)
	{
		return $coder->{'render_' . static::KIND}($this);
	}
}

// end
