<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ExceptionBlockResolver
{
	private $infer_block;
	private DeclarationChecker $declaration_checker;
	private $reduce_types;

	public function __construct(
		callable $infer_block,
		DeclarationChecker $declaration_checker,
		callable $reduce_types
	) {
		$this->infer_block = $infer_block;
		$this->declaration_checker = $declaration_checker;
		$this->reduce_types = $reduce_types;
	}

	public function infer_try_block(TryBlock $node): ?BaseType
	{
		$result_type = ($this->infer_block)($node);

		if ($node->has_exceptional()) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	public function reduce_types_with_except_block(IExceptAble $node, ?BaseType $previous_type): ?BaseType
	{
		$types = [];
		if ($previous_type) {
			$types[] = $previous_type;
		}

		foreach ($node->catchings as $sub_block) {
			$types[] = $this->infer_catch_block($sub_block);
		}

		if ($node->finally) {
			$types[] = ($this->infer_block)($node->finally);
		}

		$reduced = ($this->reduce_types)($types);
		return $reduced;
	}

	public function infer_catch_block(CatchBlock $node): ?BaseType
	{
		$var = $node->var;
		if ($var !== null) {
			if ($var->declared_type === null) {
				$var->declared_type = TypeFactory::get_base_exception_type();
			}

			$this->declaration_checker->check_variable_declaration($var);
		}

		$infered = ($this->infer_block)($node);

		return $infered;
	}
}
