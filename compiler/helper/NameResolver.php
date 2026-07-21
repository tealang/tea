<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class NameResolver
{
	private const PUBLIC_TYPE_ALIASES = [
		'Array' => 'List',
		'None' => 'Null',
	];

	private CheckerContext $context;

	public function __construct(CheckerContext $context)
	{
		$this->context = $context;
	}

	public function get_symbol_for_symbolic_node(Identifiable|BaseType $node): ?Symbol
	{
		return $node instanceof BaseType
			? TypeHelper::get_type_symbol($node)
			: ASTHelper::get_identifier_symbol($node);
	}

	public function set_symbol_for_symbolic_node(Identifiable|BaseType $node, ?Symbol $symbol): void
	{
		if ($node instanceof BaseType) {
			TypeHelper::set_type_symbol($node, $symbol);
		}
		else {
			ASTHelper::set_identifier_symbol($node, $symbol);
		}
	}

	public function find_symbol_for_type(BaseType $type): ?Symbol
	{
		$name = $type->name;
		$program = $this->context->get_program();
		$unit = $this->context->get_unit();
		$builtin_unit = $this->context->get_builtin_unit();

		$symbol = ($program ? ASTHelper::get_scope_symbol($program, $name) : null)
			?? $this->get_symbol_in_unit($unit, $name)
			?? ($builtin_unit ? $this->get_symbol_in_unit($builtin_unit, $name) : null);

		$alias_name = self::PUBLIC_TYPE_ALIASES[$name] ?? null;
		if ($symbol === null && $alias_name !== null) {
			$symbol = ($program ? ASTHelper::get_scope_symbol($program, $alias_name) : null)
				?? $this->get_symbol_in_unit($unit, $alias_name)
				?? ($builtin_unit ? $this->get_symbol_in_unit($builtin_unit, $alias_name) : null);
		}

		return $symbol;
	}

	public function find_symbol_for_plain_identifier(PlainIdentifier|TypeReference $identifier): ?Symbol
	{
		$name = $identifier->name;
		$based_ns = $identifier->ns;
		$unit = $this->context->get_unit();
		$builtin_unit = $this->context->get_builtin_unit();
		$program = $this->context->get_program();

		if ($based_ns) {
			$this->check_namespace($based_ns);
			$based_unit = ASTHelper::get_namespace_based_unit($based_ns);
			if ($based_unit === null) {
				// namespace mode
				$symbol = $this->find_symbol_by_php_use_alias_prefix($based_ns, $name)
					?? $this->find_symbol_by_current_namespace_prefix($based_ns, $name)
					?? $this->find_symbol_in_namespace($based_ns, $name, $unit)
					?? ($builtin_unit ? $this->find_symbol_in_namespace($based_ns, $name, $builtin_unit) : null);
			}
			else {
				// module mode
				$symbol = $this->get_symbol_in_unit($based_unit, $name);
			}
		}
		else {
			$symbol = ($program ? ASTHelper::get_scope_symbol($program, $name) : null)
				?? $this->find_symbol_in_current_namespace($identifier)
				?? $this->get_symbol_in_unit($unit, $name)
				?? ($builtin_unit ? $this->get_symbol_in_unit($builtin_unit, $name) : null);
		}

		return $symbol;
	}

	public function find_symbol_by_current_namespace_prefix(NamespaceIdentifier $ns, string $name): ?Symbol
	{
		$program = $this->context->get_program();
		if (!$program?->ns || $program->ns->is_global_space()) {
			return null;
		}

		$namepath = $ns->get_namepath();
		if (!$namepath || $namepath[0] === '') {
			return null;
		}

		$unit = $this->context->get_unit();
		$builtin_unit = $this->context->get_builtin_unit();
		$expanded_ns = new NamespaceIdentifier(array_merge($program->ns->get_namepath(), $namepath));
		return $this->find_symbol_in_namespace($expanded_ns, $name, $unit)
			?? ($builtin_unit ? $this->find_symbol_in_namespace($expanded_ns, $name, $builtin_unit) : null);
	}

	public function find_symbol_by_php_use_alias_prefix(NamespaceIdentifier $ns, string $name): ?Symbol
	{
		$namepath = $ns->get_namepath();
		$alias = array_shift($namepath);
		if ($alias === null) {
			return null;
		}

		$program = $this->context->get_program();
		$symbol = $program ? ASTHelper::get_scope_symbol($program, $alias) : null;
		$use_decl = $symbol?->using ?? $symbol?->declaration;
		if (!$use_decl instanceof UseDeclaration || $use_decl->import_kind !== UseDeclaration::IMPORT_CLASS) {
			return null;
		}

		$unit = $this->context->get_unit();
		$builtin_unit = $this->context->get_builtin_unit();
		$source_name = $use_decl->source_name ?? $use_decl->target_name ?? $use_decl->name;
		$expanded_names = array_merge($use_decl->ns->get_namepath(), [$source_name], $namepath);
		$expanded_ns = new NamespaceIdentifier($expanded_names);

		return $this->find_symbol_in_namespace($expanded_ns, $name, $unit)
			?? ($builtin_unit ? $this->find_symbol_in_namespace($expanded_ns, $name, $builtin_unit) : null);
	}

	public function find_symbol_in_current_namespace(PlainIdentifier|TypeReference $identifier): ?Symbol
	{
		$program = $this->context->get_program();
		$ns = $program?->ns;
		if (!$ns || $ns->is_global_space()) {
			return null;
		}

		$unit = $this->context->get_unit();
		$builtin_unit = $this->context->get_builtin_unit();
		return $this->find_symbol_in_namespace($ns, $identifier->name, $unit)
			?? ($builtin_unit ? $this->find_symbol_in_namespace($ns, $identifier->name, $builtin_unit) : null);
	}

	public function check_namespace(NamespaceIdentifier $ns): void
	{
		$names = $ns->get_namepath();
		$unit = $this->context->get_unit();

		$found_unit = null;
		while ($names) {
			$uri = join(TeaParser::NS_SEPARATOR, $names);
			$found_unit = $unit->use_units[$uri] ?? null;
			if ($found_unit !== null) {
				break;
			}

			array_pop($names);
		}

		if ($found_unit) {
			ASTHelper::set_namespace_based_unit($ns, $found_unit);
			$new_ns_names = $ns->get_namepath();
			$new_ns_names = array_slice($new_ns_names, count($found_unit->ns->names));
			$ns->set_names($new_ns_names);
		}
	}

	public function get_symbol_in_unit(Unit $unit, string $name): ?Symbol
	{
		$symbol = ASTHelper::get_scope_symbol($unit, $name);
		if ($symbol !== null) {
			$symbol->declaration->is_unit_level = true;
		}

		return $symbol;
	}

	public function get_unit_by_uri(string $uri): ?Unit
	{
		return $this->context->get_unit()->use_units[$uri] ?? null;
	}

	public function find_symbol_in_namespace(NamespaceIdentifier $ns, string $name, Unit $unit): ?Symbol
	{
		if ($this->is_namespace_unit_root($ns, $unit)) {
			return $this->get_symbol_in_unit($unit, $name);
		}

		$decl = $this->find_namespace_declaration_in_unit($unit, $ns);
		$symbol = $decl ? ASTHelper::get_scope_symbol($decl, $name) : null;
		if ($symbol !== null) {
			return $symbol;
		}

		$relative_ns = $this->get_namespace_relative_to_unit_root($ns, $unit);
		if ($relative_ns === null) {
			return null;
		}

		$decl = $this->find_namespace_declaration_in_unit($unit, $relative_ns);
		return $decl ? ASTHelper::get_scope_symbol($decl, $name) : null;
	}

	public function find_namespace_declaration_in_unit(Unit $unit, NamespaceIdentifier $ns): ?NamespaceDeclaration
	{
		$namepath = $ns->get_namepath();
		$ns_name = array_shift($namepath) ?? '';

		$decl = $unit->namespaces[$ns_name] ?? null;
		if ($decl !== null) {
			foreach ($namepath as $ns_name) {
				$decl = $decl->namespaces[$ns_name] ?? null;
				if ($decl === null) {
					break;
				}
			}
		}

		return $decl;
	}

	private function is_namespace_unit_root(NamespaceIdentifier $ns, Unit $unit): bool
	{
		$unit_ns = $unit->ns;
		return $unit_ns !== null && $ns->get_namepath() === $unit_ns->get_namepath();
	}

	private function get_namespace_relative_to_unit_root(NamespaceIdentifier $ns, Unit $unit): ?NamespaceIdentifier
	{
		$unit_ns = $unit->ns;
		if ($unit_ns === null) {
			return null;
		}

		$namepath = $ns->get_namepath();
		$unit_namepath = $unit_ns->get_namepath();
		if (count($namepath) <= count($unit_namepath)) {
			return null;
		}

		foreach ($unit_namepath as $idx => $part) {
			if (($namepath[$idx] ?? null) !== $part) {
				return null;
			}
		}

		return new NamespaceIdentifier(array_slice($namepath, count($unit_namepath)));
	}
}
