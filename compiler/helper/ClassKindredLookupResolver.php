<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ClassKindredLookupResolver
{
	private CheckerContext $context;
	private NameResolver $name_resolver;
	private VirtualSymbolFactory $virtual_symbol_factory;
	private $preprocess_classkindred_declaration;
	private $check_classkindred_declaration;

	public function __construct(
		CheckerContext $context,
		NameResolver $name_resolver,
		VirtualSymbolFactory $virtual_symbol_factory,
		callable $preprocess_classkindred_declaration,
		callable $check_classkindred_declaration
	) {
		$this->context = $context;
		$this->name_resolver = $name_resolver;
		$this->virtual_symbol_factory = $virtual_symbol_factory;
		$this->preprocess_classkindred_declaration = $preprocess_classkindred_declaration;
		$this->check_classkindred_declaration = $check_classkindred_declaration;
	}

	public function get_actual_class_declaration_for_metatype_expr(Identifiable $identifier): ClassKindredDeclaration
	{
		// the master would be an object expression, or variable of MetaType
		$decl = $this->name_resolver->get_symbol_for_symbolic_node($identifier)->declaration;
		if ($decl instanceof IVariableDeclaration) {
			$generic_type = TypeHelper::get_bound_type($decl)->generic_type;
			$decl = TypeHelper::get_type_symbol($generic_type)->declaration;
		}

		return $decl;
	}

	public function get_class_declaration_for_type(BaseType $type, BaseExpression $expr): ClassKindredDeclaration
	{
		if ($type instanceof UnionType) {
			$type_name = ASTChecker::get_type_name($type);
			throw $this->context->new_syntax_error("Cannot create instance for '$type_name'", $expr);
		}

		$symbol = TypeHelper::get_type_symbol($type);
		$decl = $symbol->declaration;

		if ($this->is_type_as_dynamic_class($type)) {
			$decl = $this->virtual_symbol_factory->create_virtual_class_declaration($symbol->name);
			$symbol->declaration = $decl;
		}
		else {
			$decl = $this->filter_classkindred_declaration($decl, $type, $expr);
		}

		return $decl;
	}

	public function get_unchecked_classkindred_declaration(PlainIdentifier|TypeReference $identifier): ClassKindredDeclaration
	{
		$symbol = $this->name_resolver->get_symbol_for_symbolic_node($identifier);
		if ($symbol === null) {
			if (!$this->context->is_weakly_checking()) {
				throw $this->context->new_syntax_error("Symbol of identifier '{$identifier->name}' not linked", $identifier);
			}

			$symbol = $this->name_resolver->find_symbol_for_plain_identifier($identifier)
				?? $this->virtual_symbol_factory->create_virtual_symbol($identifier);
			$this->name_resolver->set_symbol_for_symbolic_node($identifier, $symbol);
		}

		$decl = $symbol->declaration;
		if (!$decl instanceof ClassKindredDeclaration) {
			throw $this->context->new_syntax_error("Declaration of identifier '{$identifier->name}' not classkindred", $identifier);
		}

		$this->context->is_classkindred_ready($decl) || ($this->preprocess_classkindred_declaration)($decl);
		return $decl;
	}

	public function get_checked_classkindred_declaration(PlainIdentifier|TypeReference $identifier, bool $required = false): ClassKindredDeclaration
	{
		$symbol = $this->name_resolver->get_symbol_for_symbolic_node($identifier);
		if ($symbol === null) {
			throw $this->context->new_syntax_error("Unexpected classkindred identifier", $identifier);
		}

		$decl = $symbol->declaration;
		$type = $decl->get_hinted_type();

		if ($this->is_type_as_dynamic_class($type)) {
			$decl = $this->virtual_symbol_factory->create_virtual_class_declaration($symbol->name);
			$symbol->declaration = $decl;
		}
		else {
			$decl = $this->filter_classkindred_declaration($decl, $type, $identifier);
		}

		return $decl;
	}

	public function filter_classkindred_declaration(BaseDeclaration $decl, BaseType $type, BaseExpression|TypeReference $expr): ClassKindredDeclaration
	{
		if ($decl instanceof IVariableDeclaration && $type instanceof MetaType) {
			$decl = TypeHelper::get_type_symbol($type->generic_type)->declaration;
		}

		if ($decl instanceof ClassKindredDeclaration) {
			if (!$this->context->is_checked($decl)) {
				$temp_program = $this->context->get_program();
				$decl->program and $this->context->set_program($decl->program);
				($this->check_classkindred_declaration)($decl);
				$decl->program and $this->context->set_program($temp_program);
			}
		}
		else {
			$message = $expr instanceof PlainIdentifier
				? "Type of expression not classkindred"
				: "Declaration of '{$decl->name}' not classkindred";
			throw $this->context->new_syntax_error($message, $expr);
		}

		return $decl;
	}

	private function is_type_as_dynamic_class(BaseType $type): bool
	{
		return $this->context->is_weakly_checking() && ($type instanceof AnyType || $type instanceof MixedType || $type instanceof StringType);
	}
}
