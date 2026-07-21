<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class SymbolDeclarationResolver
{
	private CheckerContext $context;
	private NameResolver $name_resolver;
	private UseResolver $use_resolver;
	private VirtualSymbolFactory $virtual_symbol_factory;
	private DeclarationCheckTrigger $declaration_check_trigger;

	public function __construct(
		CheckerContext $context,
		NameResolver $name_resolver,
		UseResolver $use_resolver,
		VirtualSymbolFactory $virtual_symbol_factory,
		DeclarationCheckTrigger $declaration_check_trigger
	) {
		$this->context = $context;
		$this->name_resolver = $name_resolver;
		$this->use_resolver = $use_resolver;
		$this->virtual_symbol_factory = $virtual_symbol_factory;
		$this->declaration_check_trigger = $declaration_check_trigger;
	}

	public function find_type_symbol_and_check_declaration(BaseType $identifier): ?Symbol
	{
		$symbol = $identifier instanceof PlainIdentifier
			? $this->name_resolver->find_symbol_for_plain_identifier($identifier)
			: $this->name_resolver->find_symbol_for_type($identifier);

		if ($symbol === null && $identifier->name === _SUPER) {
			$symbol = $this->get_symbol_for_super_identifier($identifier);
		}

		$symbol and $this->declaration_check_trigger->check_declaration_for_symbol($symbol);

		// find in package level symbols
		return $symbol;
	}

	public function get_actual_declaration_for_identifier(Identifiable|TypeReference $identifier): BaseDeclaration
	{
		$symbol = $this->name_resolver->get_symbol_for_symbolic_node($identifier);
		if ($symbol === null) {
			if ($identifier->name === _SUPER) {
				$symbol = $this->get_symbol_for_super_identifier($identifier);
				$this->name_resolver->set_symbol_for_symbolic_node($identifier, $symbol);
			}
			elseif ($this->context->is_weakly_checking() && ($identifier instanceof PlainIdentifier || $identifier instanceof TypeReference)) {
				$symbol = $this->virtual_symbol_factory->create_virtual_symbol($identifier);
				$this->name_resolver->set_symbol_for_symbolic_node($identifier, $symbol);
			}
			else {
				throw $this->context->new_syntax_error('Missed symbol', $identifier);
			}
		}

		$decl = $symbol->declaration;
		if ($decl instanceof UseDeclaration) {
			$decl = $this->use_resolver->resolve_use_symbol_declaration($symbol, $identifier);
			if ($decl === null) {
				if ($this->context->is_weakly_checking()) {
					$symbol = $this->virtual_symbol_factory->create_virtual_symbol($identifier);
					$this->name_resolver->set_symbol_for_symbolic_node($identifier, $symbol);
					return $symbol->declaration;
				}

				throw $this->context->new_syntax_error('Missed symbol', $identifier);
			}
		}

		$this->declaration_check_trigger->check_declaration($decl, true);

		return $decl;
	}

	private function get_symbol_for_super_identifier(PlainIdentifier $identifier): Symbol
	{
		$current_method = $this->context->get_function();
		$super_identifier = $current_method->belong_block->extends[0] ?? null;
		if ($super_identifier === null) {
			if ($this->context->is_weakly_checking() && $current_method->belong_block instanceof TraitDeclaration) {
				return $this->virtual_symbol_factory->create_trait_host_super_symbol($current_method->is_static);
			}

			throw $this->context->new_syntax_error("There are not extends a class/interface for 'super' reference", $identifier);
		}

		$super_class = $this->name_resolver->get_symbol_for_symbolic_node($super_identifier)->declaration;
		return $current_method->is_static
			? $super_class->this_class_symbol
			: $super_class->this_object_symbol;
	}

}
