<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class DeclarationLinker
{
	private CheckerContext $context;
	private NameResolver $name_resolver;
	private VirtualSymbolFactory $virtual_symbol_factory;

	public function __construct(CheckerContext $context, NameResolver $name_resolver, VirtualSymbolFactory $virtual_symbol_factory)
	{
		$this->context = $context;
		$this->name_resolver = $name_resolver;
		$this->virtual_symbol_factory = $virtual_symbol_factory;
	}

	public function link_declarations(Program $program): void
	{
		$this->context->set_program($program);

		foreach ($program->declarations as $decl) {
			$this->resolve_unknown_identifiers($decl);
		}

		$program->initializer && $this->resolve_unknown_identifiers($program->initializer);
	}

	private function resolve_unknown_identifiers(BaseDeclaration $host_decl): void
	{
		$temp_program = $this->context->get_program();
		$host_decl->program and $this->context->set_program($host_decl->program);

		foreach ($host_decl->unknow_identifiers as $identifier) {
			if ($this->name_resolver->get_symbol_for_symbolic_node($identifier) !== null) {
				// eg. identifiers that in foreach arguments
				continue;
			}

			$symbol = $this->name_resolver->find_symbol_for_plain_identifier($identifier);
			if ($symbol === null) {
				if ($this->context->is_weakly_checking()) {
					if ($identifier instanceof VariableIdentifier) {
						// PHP variables, including superglobals missing from public headers, are dynamic.
						continue;
					}

					$symbol = $this->virtual_symbol_factory->create_virtual_symbol($identifier);
				}
				else {
					throw $this->context->new_syntax_error("Symbol of identifier '{$identifier->name}' not found", $identifier);
				}
			}

			$depends = $symbol->declaration;
			if ($depends instanceof UseDeclaration) {
				$source = ASTHelper::get_use_source_declaration($depends);
				if ($source) {
					$symbol->declaration = $source;
					$symbol->using = $depends;
					$host_decl->append_use_declaration($depends);
				}
				else {
					$symbol = $this->virtual_symbol_factory->create_virtual_symbol($identifier);
				}
			}
			elseif ($symbol->using) {
				$host_decl->append_use_declaration($symbol->using);
			}

			$this->name_resolver->set_symbol_for_symbolic_node($identifier, $symbol);
		}

		$host_decl->program and $this->context->set_program($temp_program);
	}
}
