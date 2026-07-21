<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class CallableDeclarationResolver
{
	private CheckerContext $context;
	private FunctionBodyResolver $function_body_resolver;
	private ClassBodyResolver $class_body_resolver;
	private AnonymousFunctionResolver $anonymous_function_resolver;

	public function __construct(
		CheckerContext $context,
		FunctionBodyResolver $function_body_resolver,
		ClassBodyResolver $class_body_resolver,
		AnonymousFunctionResolver $anonymous_function_resolver
	) {
		$this->context = $context;
		$this->function_body_resolver = $function_body_resolver;
		$this->class_body_resolver = $class_body_resolver;
		$this->anonymous_function_resolver = $anonymous_function_resolver;
	}

	public function check_callable_declaration(ICallableDeclaration $node): void
	{
		if ($this->context->is_checking($node)) {
			throw $this->context->new_syntax_error("Function '{$node->name}' has a circular checking, needs a return type", $node);
		}

		$this->context->mark_checking($node);

		switch ($node::KIND) {
			case MethodDeclaration::KIND:
			case MemberMappingDeclaration::KIND:
				$this->class_body_resolver->check_method_declaration($node);
				break;

			case FunctionDeclaration::KIND:
				$this->check_function_declaration($node);
				break;

			case AnonymousFunction::KIND:
				$this->anonymous_function_resolver->infer_anonymous_function($node);
				break;

			default:
				$kind = $node::KIND;
				throw $this->context->new_syntax_error("Unexpect callable declaration kind: '{$kind}'", $node);
		}
	}

	public function should_check_callable_declaration(ICallableDeclaration $node): bool
	{
		return $node instanceof MethodDeclaration
			|| $node instanceof MemberMappingDeclaration
			|| $node instanceof FunctionDeclaration
			|| $node instanceof AnonymousFunction;
	}

	public function check_function_declaration(FunctionDeclaration $node): void
	{
		if ($this->context->is_checked($node)) return;
		$this->context->mark_checked($node);

		$this->function_body_resolver->check_function_kindred($node);
	}
}
