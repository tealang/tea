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

	/**
	 * The first token position of current node
	 */
	public int $pos = 0;

	/**
	 * Is there a line break at the head
	 */
	public bool $leading_br = false;

	/**
	 * Is there a line break at the end
	 */
	public bool $tailing_br = false;

	/**
	 * Doc comment
	 */
	public ?DocComment $doc = null;

	/**
	 * Tailing inline comment
	 */
	public ?string $tailing_comment = null;

	/**
	 * Indentation string
	 */
	public ?string $indents = null;

	public function render(BaseCoder $coder): ?string
	{
		return $coder->render_node($this);
	}
}

// end
