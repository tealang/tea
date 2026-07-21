<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class AnonymousFunctionResolver
{
	private CheckerContext $context;
	private NameResolver $name_resolver;
	private FunctionBodyResolver $function_body_resolver;
	private $infer_plain_identifier;
	private $infer_expression;

	public function __construct(
		CheckerContext $context,
		NameResolver $name_resolver,
		FunctionBodyResolver $function_body_resolver,
		callable $infer_plain_identifier,
		callable $infer_expression
	) {
		$this->context = $context;
		$this->name_resolver = $name_resolver;
		$this->function_body_resolver = $function_body_resolver;
		$this->infer_plain_identifier = $infer_plain_identifier;
		$this->infer_expression = $infer_expression;
	}

	public function infer_anonymous_function(AnonymousFunction $node): BaseType
	{
		foreach ($node->unknow_identifiers as $identifier) {
			if (!$this->name_resolver->get_symbol_for_symbolic_node($identifier)) {
				if ($identifier instanceof PlainIdentifier) {
					($this->infer_plain_identifier)($identifier);
				}
				elseif ($identifier instanceof TypeReference) {
					TypeHelper::set_type_symbol($identifier, $this->name_resolver->find_symbol_for_plain_identifier($identifier));
				}
			}

			if ($this->name_resolver->get_symbol_for_symbolic_node($identifier)->declaration instanceof IVariableDeclaration) {
				if ($identifier->name === _THIS || $identifier->name === _SUPER) {
					throw $this->context->new_syntax_error("'{$identifier->name}' cannot use in lambda functions", $node);
				}

				$name = $identifier->name;
				$param = new ParameterDeclaration($name);
				$param->is_inout = true;
				$node->using_params[$name] = $param;
			}
		}

		$this->function_body_resolver->check_function_kindred($node);

		return TypeFactory::create_callable_type($node->get_expressed_type(), $node->parameters);
	}

	public function infer_callback_argument(CallbackArgument $node): BaseType
	{
		$value_expr = $node->value;
		if ($value_expr instanceof AnonymousFunction) {
			$this->infer_anonymous_function($value_expr);
			$decl = $value_expr;
		}
		else {
			($this->infer_expression)($value_expr);
			$decl = $value_expr instanceof Identifiable
				? ASTHelper::get_identifier_symbol($value_expr)->declaration
				: null;
		}

		if (!$decl instanceof IValuableDeclaration) {
			return TypeFactory::$_mixed;
		}

		return $decl->get_expressed_type();
	}
}
