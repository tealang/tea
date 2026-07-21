<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class InstancingResolver
{
	private CheckerContext $context;
	private MemberResolver $member_resolver;

	public function __construct(CheckerContext $context, MemberResolver $member_resolver)
	{
		$this->context = $context;
		$this->member_resolver = $member_resolver;
	}

	public function infer_instancing_expr(BaseExpression $callee, BaseDeclaration $decl): BaseType
	{
		if (!$decl instanceof ClassDeclaration) {
			throw $this->context->new_syntax_error("Cannot instantiate '{$decl->name}'", $callee);
		}

		if ($this->is_dynamic_class_declaration($decl)) {
			return TypeFactory::$_mixed;
		}

		return $decl->typing_identifier;
	}

	private function is_dynamic_class_declaration(ClassDeclaration $decl): bool
	{
		return $decl->is_virtual && str_starts_with($decl->name, '$');
	}

	public function get_construct_declaration_for_class(ClassDeclaration $class, BaseCallExpression $call)
	{
		if ($this->is_dynamic_class_declaration($class)) {
			return null;
		}

		$symbol = $this->member_resolver->find_member_symbol_in_class_declaration($class, _CONSTRUCT);
		if (!$symbol) {
			return null; // no any to check
		}

		return $symbol->declaration;
	}
}
