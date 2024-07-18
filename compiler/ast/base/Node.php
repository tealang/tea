<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
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
	 * @var Docs
	 */
	public $docs;

	// tailing inline comment
	public $tailing_comment;

	public $indents;

	public function render(TeaCoder $coder)
	{
		return $coder->{'render_' . static::KIND}($this);
	}
}
