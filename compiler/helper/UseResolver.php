<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class UseResolver
{
	private CheckerContext $context;
	private NameResolver $name_resolver;
	private VirtualSymbolFactory $virtual_symbol_factory;

	public function __construct(
		CheckerContext $context,
		NameResolver $name_resolver,
		VirtualSymbolFactory $virtual_symbol_factory
	) {
		$this->context = $context;
		$this->name_resolver = $name_resolver;
		$this->virtual_symbol_factory = $virtual_symbol_factory;
	}

	public function resolve_use_target(UseDeclaration $use): void
	{
		$unit = $this->resolve_uses_unit($use->ns);
		if ($unit) {
			$this->link_source_declaration_for_use($use, $unit);
		}
		elseif ($this->try_link_source_declaration_for_same_unit_use($use)) {
			return;
		}
		elseif (!$this->context->is_weakly_checking()) {
			$error = $this->context->new_syntax_error("Package '{$use->ns->uri}' not found", $use->ns);
			throw $error;
		}
	}

	public function resolve_use_symbol_declaration(Symbol $symbol, Identifiable|TypeReference|null $identifier = null): ?BaseDeclaration
	{
		$use = $symbol->declaration;
		if (!$use instanceof UseDeclaration) {
			return $use;
		}

		if (!$this->context->is_checked($use)) {
			$this->resolve_use_target($use);
		}

		$source = ASTHelper::get_use_source_declaration($use);
		if ($source instanceof BaseDeclaration) {
			$symbol->declaration = $source;
			$symbol->using = $use;
			return $source;
		}

		if ($this->context->is_weakly_checking() && $identifier instanceof TypeReference) {
			$virtual_symbol = $this->virtual_symbol_factory->create_virtual_symbol($identifier);
			$virtual_symbol->using = $use;
			$this->name_resolver->set_symbol_for_symbolic_node($identifier, $virtual_symbol);
			return $virtual_symbol->declaration;
		}

		return null;
	}

	public function resolve_uses_unit(NamespaceIdentifier $ns): ?Unit
	{
		$current_unit = $this->context->get_unit();
		$unit = $this->name_resolver->get_unit_by_uri($ns->uri);
		if ($unit === null) {
			// for PHP uses
			if ($ns->uri === $current_unit->name) {
				$unit = $current_unit;
			}
		}

		return $unit;
	}

	private function try_link_source_declaration_for_same_unit_use(UseDeclaration $use): bool
	{
		$name = $use->source_name ?? $use->target_name;
		if ($name === null) {
			return false;
		}

		$unit = $this->context->get_unit();
		$builtin_unit = $this->context->get_builtin_unit();
		$symbol = $use->ns->is_global_space()
			? ($this->name_resolver->get_symbol_in_unit($unit, $name)
				?? ($builtin_unit ? $this->name_resolver->get_symbol_in_unit($builtin_unit, $name) : null))
			: ($this->name_resolver->find_symbol_in_namespace($use->ns, $name, $unit)
				?? ($builtin_unit ? $this->name_resolver->find_symbol_in_namespace($use->ns, $name, $builtin_unit) : null));
		if ($symbol === null) {
			return false;
		}

		$target_declaration = $symbol->declaration;
		if ($use->import_kind !== null) {
			$this->check_use_import_kind($use, $target_declaration);
		}

		ASTHelper::set_use_source_declaration($use, $target_declaration);
		$this->context->mark_checked($use);

		return true;
	}

	private function link_source_declaration_for_use(UseDeclaration $use, Unit $unit): void
	{
		$name = $use->source_name ?? $use->target_name;
		if ($name) {
			$symbol = ASTHelper::get_scope_symbol($unit, $name);
			if ($symbol === null) {
				$target_declaration = $this->find_namespace_declaration_for_use($use, $unit);
				if ($target_declaration === null) {
					$error = $this->context->new_syntax_error("Target '{$name}' for use not found in package '{$unit->name}'", $use);
					throw $error;
				}
			}
			else {
				$target_declaration = $symbol->declaration;
			}
		}
		else {
			$target_declaration = $unit;
		}

		if ($use->import_kind !== null) {
			$this->check_use_import_kind($use, $target_declaration);
		}

		ASTHelper::set_use_source_declaration($use, $target_declaration);
		$this->context->mark_checked($use);
	}

	private function find_namespace_declaration_for_use(UseDeclaration $use, Unit $unit): ?NamespaceDeclaration
	{
		$name = $use->source_name ?? $use->target_name;
		if ($name === null) {
			return null;
		}

		$namespaces = [];
		if (!$use->ns->is_global_space()) {
			$namespaces[] = new NamespaceIdentifier(array_merge($use->ns->get_namepath(), [$name]));
		}

		$namespaces[] = new NamespaceIdentifier([$name]);

		foreach ($namespaces as $ns) {
			$decl = $this->name_resolver->find_namespace_declaration_in_unit($unit, $ns);
			if ($decl !== null) {
				return $decl;
			}
		}

		return null;
	}

	private function check_use_import_kind(UseDeclaration $use, Unit|BaseDeclaration $target_declaration): void
	{
		$is_matched = false;
		if ($use->import_kind === UseDeclaration::IMPORT_CLASS) {
			$is_matched = $target_declaration instanceof ClassKindredDeclaration
				|| $target_declaration instanceof NamespaceDeclaration;
		}
		elseif ($use->import_kind === UseDeclaration::IMPORT_FUNCTION) {
			$is_matched = $target_declaration instanceof FunctionDeclaration;
		}
		elseif ($use->import_kind === UseDeclaration::IMPORT_CONST) {
			$is_matched = $target_declaration instanceof ConstantDeclaration;
		}

		if ($is_matched) {
			return;
		}

		$name = $use->source_name ?? $use->target_name ?? $use->ns->uri;
		$actual_kind = $this->describe_use_import_declaration($target_declaration);
		throw $this->context->new_syntax_error("Target '{$name}' for use expects {$use->import_kind}, {$actual_kind} given", $use);
	}

	private function describe_use_import_declaration(Unit|BaseDeclaration $target_declaration): string
	{
		if ($target_declaration instanceof Unit) {
			return 'namespace';
		}
		elseif ($target_declaration instanceof ClassKindredDeclaration) {
			return 'class';
		}
		elseif ($target_declaration instanceof FunctionDeclaration) {
			return 'function';
		}
		elseif ($target_declaration instanceof ConstantDeclaration) {
			return 'const';
		}

		return 'declaration';
	}
}
