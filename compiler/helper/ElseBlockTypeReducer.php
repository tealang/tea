<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ElseBlockTypeReducer
{
	private $infer_else_block;
	private $reduce_types;

	public function __construct(
		callable $infer_else_block,
		callable $reduce_types
	) {
		$this->infer_else_block = $infer_else_block;
		$this->reduce_types = $reduce_types;
	}

	public function reduce_types_with_else_block(IElseAble $node, ?BaseType $previous_type): ?BaseType
	{
		$infered = ($this->infer_else_block)($node->else);
		return $previous_type
			? ($this->reduce_types)([$previous_type, $infered])
			: $infered;
	}
}
