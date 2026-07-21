<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class CompileTimeValueChecker
{
	private CheckerContext $context;
	private NameResolver $name_resolver;
	private $infer_expression;

	public function __construct(
		CheckerContext $context,
		NameResolver $name_resolver,
		callable $infer_expression
	) {
		$this->context = $context;
		$this->name_resolver = $name_resolver;
		$this->infer_expression = $infer_expression;
	}

	public function assert_compile_time_value_for(IValuableDeclaration $node): void
	{
		$value = $node->value;

		// PHP native path is weakly checked; allow runtime-acceptable initializers
		// that are not yet fully modeled by Tea compile-time const expression rules.
		if ($this->context->is_weakly_checking()) {
			return;
		}

		if (!$this->is_const_expr($value) and !$node instanceof ObjectMember) {
			$error = $this->context->new_syntax_error("Invalid initial value expression", $value);
			throw $error;
		}

		if ($value instanceof BinaryOperation) {
			if ($value->operator->is(OPID::CONCAT)) {
				$left_type = ($this->infer_expression)($value->left);
				if ($left_type instanceof ArrayType) {
					$error = $this->context->new_syntax_error("List concat operation cannot use as a compile-time value", $value);
					throw $error;
				}
			}
			// elseif ($value->operator->is(OPID::MERGE)) {
			// 	throw $this->new_syntax_error("Dict merge operation cannot use for constant value", $value);
			// }
		}
	}

	public function is_const_expr(BaseExpression $node): bool
	{
		// if ($node instanceof ILiteral || $node instanceof ConstantIdentifier) {
		// 	$result = true;
		// }
		// else
		if ($node instanceof Identifiable) {
			$decl = $this->name_resolver->get_symbol_for_symbolic_node($node)->declaration;
			$result = $decl instanceof IConstantDeclaration || $decl instanceof ClassKindredDeclaration;
		}
		elseif ($node instanceof BinaryOperation) {
			$result = $this->is_const_expr($node->left) && $this->is_const_expr($node->right);
		}
		elseif ($node instanceof PrefixOperation) {
			$result = $this->is_const_expr($node->expression);
		}
		elseif ($node instanceof ArrayExpression) {
			$result = true;
			foreach ($node->items as $item) {
				if (!$this->is_const_expr($item)) {
					$result = false;
					break;
				}
			}
		}
		elseif ($node instanceof DictExpression) {
			$result = true;
			foreach ($node->items as $item) {
				if (!$this->is_const_expr($item->key) || !$this->is_const_expr($item->value)) {
					$result = false;
					break;
				}
			}
		}
		// elseif ($node instanceof XTag) {
		// 	$result = $node->is_const_value;
		// }
		else {
			$result = $node->is_const_value ?? false;
		}

		return $result;
	}
}
