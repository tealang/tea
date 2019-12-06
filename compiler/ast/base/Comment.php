<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class InlineComments extends Node implements IStatement
{
	const KIND = 'inline_comments';

	public $items;

	public function __construct(string ...$items)
	{
		$this->items = $items;
	}
}

class BlockComment extends Node implements IStatement
{
	const KIND = 'block_comment';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}
