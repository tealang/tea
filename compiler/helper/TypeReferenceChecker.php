<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TypeReferenceChecker
{
	private CheckerContext $context;
	private NameResolver $name_resolver;
	private SymbolDeclarationResolver $declaration_resolver;
	private DeclarationCheckTrigger $declaration_check_trigger;

	public function __construct(
		CheckerContext $context,
		NameResolver $name_resolver,
		SymbolDeclarationResolver $symbol_declaration_resolver,
		DeclarationCheckTrigger $declaration_check_trigger
	) {
		$this->context = $context;
		$this->name_resolver = $name_resolver;
		$this->declaration_resolver = $symbol_declaration_resolver;
		$this->declaration_check_trigger = $declaration_check_trigger;
	}

	/**
	 * @param IDeclaration|BaseExpression|null $node
	 */
	public function check_type(BaseType $type, $node = null): void
	{
		if ($type instanceof InvalidType) {
			return;
		}

		if ($type instanceof TypeReference) {
			$decl = $this->declaration_resolver->get_actual_declaration_for_identifier($type);
			if (!$decl instanceof ClassKindredDeclaration) {
				$name = ASTChecker::get_declaration_name($decl);
				$error = $this->context->new_syntax_error("Cannot use '$name' as a type reference", $type);
				throw $error;
			}
		}
		elseif ($type instanceof BaseType) {
			if (!$this->name_resolver->get_symbol_for_symbolic_node($type)) {
				$this->name_resolver->set_symbol_for_symbolic_node($type, $this->declaration_resolver->find_type_symbol_and_check_declaration($type));
			}

			if ($type instanceof SingleGenericType) {
				// check the value type
				$type->generic_type !== null && $this->check_type($type->generic_type);
			}
			elseif ($type instanceof UnionType) {
				foreach ($type->get_members() as $member) {
					$this->check_type($member);
				}
			}
			elseif ($type instanceof IntersectionType) {
				foreach ($type->get_members() as $member) {
					$this->check_type($member);
				}
			}
			elseif ($type instanceof CallableType) {
				$this->check_callable_type($type);
			}

			// no any other need to check
		}
		else {
			$kind = $type::KIND;
			$error = $this->context->new_syntax_error("Unknow type kind '$kind'", $type);
			throw $error;
		}
	}

	public function check_callable_type(CallableType $node): void
	{
		$this->context->mark_checked($node);

		$hinted = $this->get_and_check_hinted_type($node);
		$node->infered_type = $hinted ?? TypeFactory::$_void;

		$node->parameters and $this->declaration_check_trigger->check_parameters_for_callable_declaration($node);
	}

	public function get_and_check_hinted_type(IDeclaration|AnonymousFunction|CallableType $node): ?BaseType
	{
		$noted = ASTHelper::get_noted_type($node);
		$declared = $node->declared_type;

		if ($noted) {
			$this->check_type($noted, $node);
		}

		if ($declared) {
			$this->check_type($declared, $node);
		}

		return $noted ?? $declared;
	}
}
