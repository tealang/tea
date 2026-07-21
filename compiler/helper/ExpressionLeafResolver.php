<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ExpressionLeafResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private KeyAccessResolver $key_access_resolver;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		KeyAccessResolver $key_access_resolver
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->key_access_resolver = $key_access_resolver;
	}

	public function infer_yield_expression(YieldExpression $node): BaseType
	{
		($this->infer_expression)($node->argument);
		return TypeFactory::$_mixed;
	}

	public function infer_throw_expression(ThrowExpression $node): BaseType
	{
		($this->infer_expression)($node->argument);
		return TypeFactory::$_mixed;
	}

	public function infer_include_expression(IncludeExpression $node): ?BaseType
	{
		$infered = ($this->infer_expression)($node->target);
		$infered = TypeHelper::unwrap_excludable_type($infered);
		if (!$infered instanceof StringType
			&& !$this->allow_dynamic_include_target($infered)) {
			$type_name = ASTChecker::get_type_name($infered);
			throw $this->context->new_syntax_error("Expected String type expression, got '{$type_name}'", $node->target);
		}

		return TypeFactory::$_mixed;
	}

	public function infer_first_class_callable_expression(FirstClassCallableExpression $node): BaseType
	{
		$infered = ($this->infer_expression)($node->callee);
		if ($infered instanceof CallableType) {
			return $infered;
		}

		if ($this->allow_dynamic_first_class_callable($infered)) {
			return TypeFactory::$_callable;
		}

		$type_name = ASTChecker::get_type_name($infered);
		throw $this->context->new_syntax_error("Expected callable expression, got '$type_name'", $node);
	}

	private function allow_dynamic_include_target(BaseType $type): bool
	{
		return $this->context->is_weakly_checking()
			&& ($type instanceof AnyType
				|| $type instanceof MixedType
				|| ($type instanceof UnionType && $this->key_access_resolver->is_string_key_accessible_union($type)));
	}

	private function allow_dynamic_first_class_callable(BaseType $type): bool
	{
		return $this->context->is_weakly_checking()
			&& PHPWeakPolicy::is_dynamic_top_type($type);
	}
}
