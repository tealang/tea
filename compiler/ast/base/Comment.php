<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

// "// ..."
class LineComment extends Node implements IStatement
{
	const KIND = 'line_comment';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

// "/* ... */"
class BlockComment extends Node implements IStatement
{
	const KIND = 'block_comment';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

// "/** ... */"
class DocComment extends Node implements IStatement
{
	const KIND = 'doc_comment';

	public $content;

	public function __construct(string $content)
	{
		$this->content = $content;
	}
}

// end
