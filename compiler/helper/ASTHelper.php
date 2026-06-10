<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ASTHelper
{
	public const NOTED_TYPE_SOURCE_EXPLICIT = 'explicit';
	public const NOTED_TYPE_SOURCE_HEADER = 'header';
	public const NOTED_TYPE_SOURCE_PHPDOC = 'phpdoc';

	private static ?\SplObjectStorage $expression_types = null;
	private static ?\SplObjectStorage $callee_declarations = null;
	private static ?\SplObjectStorage $normalized_arguments = null;
	private static ?\SplObjectStorage $trait_members = null;
	private static ?\SplObjectStorage $aggregated_members = null;
	private static ?\SplObjectStorage $use_source_declarations = null;
	private static ?\SplObjectStorage $namespace_based_units = null;
	private static ?\SplObjectStorage $php_true_assertions = null;
	private static ?\SplObjectStorage $php_true_property_non_null_assertions = null;

	public static function reset_semantic_state(): void
	{
		self::$expression_types = null;
		self::$callee_declarations = null;
		self::$normalized_arguments = null;
		self::$trait_members = null;
		self::$aggregated_members = null;
		self::$use_source_declarations = null;
		self::$namespace_based_units = null;
		self::$php_true_assertions = null;
		self::$php_true_property_non_null_assertions = null;
	}

	public static function get_expressed_type(BaseExpression $expr): ?BaseType
	{
		$table = self::$expression_types;
		if ($table !== null && isset($table[$expr])) {
			return $table[$expr];
		}

		return null;
	}

	public static function set_expressed_type(BaseExpression $expr, ?BaseType $type): void
	{
		if ($type === null) {
			if (self::$expression_types !== null) {
				unset(self::$expression_types[$expr]);
			}
			return;
		}

		$table = self::get_expression_type_table();
		$table[$expr] = $type;
	}

	private static function get_expression_type_table(): \SplObjectStorage
	{
		return self::$expression_types ??= new \SplObjectStorage();
	}

	public static function get_identifier_symbol(Identifiable $identifier): ?Symbol
	{
		return $identifier->symbol;
	}

	public static function set_identifier_symbol(Identifiable $identifier, ?Symbol $symbol): void
	{
		$identifier->symbol = $symbol;
	}

	public static function get_object_expression_symbol(ObjectExpression $expr): ?Symbol
	{
		return $expr->symbol;
	}

	public static function set_object_expression_symbol(ObjectExpression $expr, ?Symbol $symbol): void
	{
		$expr->symbol = $symbol;
	}

	/**
	 * @return array<string, Symbol>
	 */
	public static function get_scope_symbols(Unit|Program|NamespaceDeclaration|ClassKindredDeclaration|IBlock $scope): array
	{
		if ($scope instanceof IBlock) {
			return $scope->get_symbols();
		}

		return $scope->symbols ?? [];
	}

	public static function get_scope_symbol(Unit|Program|NamespaceDeclaration|ClassKindredDeclaration|IBlock $scope, string $name): ?Symbol
	{
		if ($scope instanceof IBlock) {
			return $scope->get_symbol($name);
		}

		return $scope->symbols[$name] ?? null;
	}

	public static function set_scope_symbol(Unit|Program|NamespaceDeclaration|ClassKindredDeclaration|IBlock $scope, string $name, Symbol $symbol): void
	{
		if ($scope instanceof IBlock) {
			$scope->set_symbol($name, $symbol);
			return;
		}

		if ($scope instanceof Unit && $scope->symbols === null) {
			$scope->symbols = [];
		}

		$scope->symbols[$name] = $symbol;
	}

	public static function unset_scope_symbol(Unit|Program|NamespaceDeclaration|ClassKindredDeclaration|IBlock $scope, string $name): void
	{
		if ($scope instanceof IBlock) {
			unset($scope->symbols[$name]);
			return;
		}

		if ($scope instanceof Unit && $scope->symbols === null) {
			return;
		}

		unset($scope->symbols[$name]);
	}

	/**
	 * @return BaseDeclaration|BaseType|AnonymousFunction|null
	 */
	public static function get_callee_declaration(BaseCallExpression $expr)
	{
		$table = self::$callee_declarations;
		if ($table !== null && isset($table[$expr])) {
			return $table[$expr];
		}

		return null;
	}

	public static function set_callee_declaration(BaseCallExpression $expr, $decl): void
	{
		if ($decl === null) {
			if (self::$callee_declarations !== null) {
				unset(self::$callee_declarations[$expr]);
			}
			return;
		}

		$table = self::get_callee_declaration_table();
		$table[$expr] = $decl;
	}

	private static function get_callee_declaration_table(): \SplObjectStorage
	{
		return self::$callee_declarations ??= new \SplObjectStorage();
	}

	public static function get_normalized_arguments(BaseCallExpression $expr): ?array
	{
		$table = self::$normalized_arguments;
		if ($table !== null && isset($table[$expr])) {
			return $table[$expr];
		}

		return null;
	}

	public static function set_normalized_arguments(BaseCallExpression $expr, ?array $arguments): void
	{
		if ($arguments === null) {
			if (self::$normalized_arguments !== null) {
				unset(self::$normalized_arguments[$expr]);
			}
			return;
		}

		$table = self::get_normalized_arguments_table();
		$table[$expr] = $arguments;
	}

	private static function get_normalized_arguments_table(): \SplObjectStorage
	{
		return self::$normalized_arguments ??= new \SplObjectStorage();
	}

	public static function get_trait_members(ClassKindredDeclaration $decl): array
	{
		$table = self::$trait_members;
		if ($table !== null && isset($table[$decl])) {
			return $table[$decl];
		}

		return [];
	}

	public static function set_trait_members(ClassKindredDeclaration $decl, array $members): void
	{
		if ($members === []) {
			if (self::$trait_members !== null) {
				unset(self::$trait_members[$decl]);
			}
			return;
		}

		$table = self::get_trait_members_table();
		$table[$decl] = $members;
	}

	private static function get_trait_members_table(): \SplObjectStorage
	{
		return self::$trait_members ??= new \SplObjectStorage();
	}

	public static function get_aggregated_members(ClassKindredDeclaration $decl): array
	{
		$table = self::$aggregated_members;
		if ($table !== null && isset($table[$decl])) {
			return $table[$decl];
		}

		return [];
	}

	public static function set_aggregated_members(ClassKindredDeclaration $decl, array $members): void
	{
		if ($members === []) {
			if (self::$aggregated_members !== null) {
				unset(self::$aggregated_members[$decl]);
			}
			return;
		}

		$table = self::get_aggregated_members_table();
		$table[$decl] = $members;
	}

	private static function get_aggregated_members_table(): \SplObjectStorage
	{
		return self::$aggregated_members ??= new \SplObjectStorage();
	}

	public static function get_use_source_declaration(UseDeclaration $decl): Unit|BaseDeclaration|null
	{
		$table = self::$use_source_declarations;
		if ($table !== null && isset($table[$decl])) {
			return $table[$decl];
		}

		return null;
	}

	public static function set_use_source_declaration(UseDeclaration $decl, Unit|BaseDeclaration|null $source): void
	{
		if ($source === null) {
			if (self::$use_source_declarations !== null) {
				unset(self::$use_source_declarations[$decl]);
			}
			return;
		}

		$table = self::get_use_source_declaration_table();
		$table[$decl] = $source;
	}

	private static function get_use_source_declaration_table(): \SplObjectStorage
	{
		return self::$use_source_declarations ??= new \SplObjectStorage();
	}

	public static function get_namespace_based_unit(NamespaceIdentifier $ns): ?Unit
	{
		$table = self::$namespace_based_units;
		if ($table !== null && isset($table[$ns])) {
			return $table[$ns];
		}

		return null;
	}

	public static function set_namespace_based_unit(NamespaceIdentifier $ns, ?Unit $unit): void
	{
		if ($unit === null) {
			if (self::$namespace_based_units !== null) {
				unset(self::$namespace_based_units[$ns]);
			}
			return;
		}

		$table = self::get_namespace_based_unit_table();
		$table[$ns] = $unit;
	}

	private static function get_namespace_based_unit_table(): \SplObjectStorage
	{
		return self::$namespace_based_units ??= new \SplObjectStorage();
	}

	public static function get_namespace_last_name(NamespaceIdentifier $ns): ?string
	{
		if ($ns->names) {
			return $ns->names[count($ns->names) - 1];
		}

		$based_ns = self::get_namespace_based_unit($ns)?->ns;
		return $based_ns instanceof NamespaceIdentifier ? self::get_namespace_last_name($based_ns) : null;
	}

	public static function get_noted_type(BaseDeclaration|AnonymousFunction|CallableType $decl): ?BaseType
	{
		return $decl->noted_type;
	}

	public static function set_noted_type(BaseDeclaration|AnonymousFunction|CallableType $decl, ?BaseType $type, ?string $source = null): void
	{
		$decl->noted_type = $type;
		$decl->noted_type_source = $type === null ? null : ($source ?? self::NOTED_TYPE_SOURCE_EXPLICIT);
		$decl->noted_type_from_phpdoc = $decl->noted_type_source === self::NOTED_TYPE_SOURCE_PHPDOC;
	}

	public static function is_noted_type_from_phpdoc(BaseDeclaration|AnonymousFunction|CallableType $decl): bool
	{
		return $decl->noted_type_from_phpdoc || $decl->noted_type_source === self::NOTED_TYPE_SOURCE_PHPDOC;
	}

	public static function is_noted_type_from_header(BaseDeclaration|AnonymousFunction|CallableType $decl): bool
	{
		return $decl->noted_type_source === self::NOTED_TYPE_SOURCE_HEADER;
	}

	public static function is_noted_type_explicit(BaseDeclaration|AnonymousFunction|CallableType $decl): bool
	{
		return $decl->noted_type_source === self::NOTED_TYPE_SOURCE_EXPLICIT;
	}

	public static function is_noted_type_trusted_contract(BaseDeclaration|AnonymousFunction|CallableType $decl): bool
	{
		return self::is_noted_type_from_header($decl) || self::is_noted_type_explicit($decl);
	}

	public static function set_noted_type_from_phpdoc(BaseDeclaration|AnonymousFunction|CallableType $decl, bool $from_phpdoc): void
	{
		$decl->noted_type_from_phpdoc = $from_phpdoc;
		if ($from_phpdoc && $decl->noted_type !== null) {
			$decl->noted_type_source = self::NOTED_TYPE_SOURCE_PHPDOC;
		}
		elseif (!$from_phpdoc && $decl->noted_type_source === self::NOTED_TYPE_SOURCE_PHPDOC) {
			$decl->noted_type_source = $decl->noted_type === null ? null : self::NOTED_TYPE_SOURCE_EXPLICIT;
		}
	}

	public static function get_noted_type_source(BaseDeclaration|AnonymousFunction|CallableType $decl): ?string
	{
		return $decl->noted_type_source;
	}

	public static function set_noted_type_source(BaseDeclaration|AnonymousFunction|CallableType $decl, ?string $source): void
	{
		$decl->noted_type_source = $source;
		$decl->noted_type_from_phpdoc = $source === self::NOTED_TYPE_SOURCE_PHPDOC;
	}

	public static function is_noted_type_nullable_inherited(BaseDeclaration|AnonymousFunction|CallableType $decl): bool
	{
		return $decl->noted_type_nullable_inherited;
	}

	public static function set_noted_type_nullable_inherited(BaseDeclaration|AnonymousFunction|CallableType $decl, bool $nullable_inherited): void
	{
		$decl->noted_type_nullable_inherited = $nullable_inherited;
	}

	public static function get_php_true_assertions(IFunctionDeclaration $decl): array
	{
		$table = self::$php_true_assertions;
		if ($table !== null && isset($table[$decl])) {
			return $table[$decl];
		}

		return [];
	}

	public static function set_php_true_assertions(IFunctionDeclaration $decl, array $assertions): void
	{
		if ($assertions === []) {
			if (self::$php_true_assertions !== null) {
				unset(self::$php_true_assertions[$decl]);
			}
			return;
		}

		$table = self::get_php_true_assertions_table();
		$table[$decl] = $assertions;
	}

	private static function get_php_true_assertions_table(): \SplObjectStorage
	{
		return self::$php_true_assertions ??= new \SplObjectStorage();
	}

	public static function get_php_true_property_non_null_assertions(IFunctionDeclaration $decl): array
	{
		$table = self::$php_true_property_non_null_assertions;
		if ($table !== null && isset($table[$decl])) {
			return $table[$decl];
		}

		return [];
	}

	public static function set_php_true_property_non_null_assertions(IFunctionDeclaration $decl, array $properties): void
	{
		if ($properties === []) {
			if (self::$php_true_property_non_null_assertions !== null) {
				unset(self::$php_true_property_non_null_assertions[$decl]);
			}
			return;
		}

		$table = self::get_php_true_property_non_null_assertions_table();
		$table[$decl] = $properties;
	}

	private static function get_php_true_property_non_null_assertions_table(): \SplObjectStorage
	{
		return self::$php_true_property_non_null_assertions ??= new \SplObjectStorage();
	}

	public static function is_pure_bracket_accessing_expr(BaseExpression $expr)
	{
		return $expr instanceof BracketAccessing
			&& ($expr->basing instanceof PlainIdentifier
				|| self::is_pure_bracket_accessing_expr($expr->basing));
	}

	public static function is_assignable_expr(BaseExpression $expr)
	{
		return $expr instanceof Identifiable && self::is_assignable_identifier($expr)
			|| $expr instanceof KeyAccessing && self::is_mutable_expr($expr->basing)
			|| $expr instanceof SquareAccessing && self::is_mutable_expr($expr->basing)
			|| $expr instanceof Destructuring
			|| $expr instanceof BinaryOperation && $expr->operator === OperatorFactory::$member_accessing
			;
	}

	public static function is_mutable_expr(BaseExpression $expr)
	{
		return $expr instanceof Identifiable && self::is_mutable_identifier($expr)
			|| $expr instanceof KeyAccessing && self::is_mutable_expr($expr->basing)
			|| $expr instanceof BinaryOperation && $expr->operator === OperatorFactory::$member_accessing;
	}

	public static function is_assignable_identifier(Identifiable $expr)
	{
		$symbol = self::get_identifier_symbol($expr);
		if ($expr instanceof AccessingIdentifier && $symbol === null) {
			return true;
		}

		if ($symbol === null) {
			return false;
		}

		$decl = $symbol->declaration;
		if ($decl instanceof BaseVariableDeclaration) {
			return !$decl->is_final;
		}

		if ($decl instanceof SuperVariableDeclaration) {
			return !$decl->is_final;
		}

		if ($decl instanceof PropertyDeclaration) {
			return !$decl->is_final;
		}

		return false;
	}

	public static function is_mutable_identifier(Identifiable $expr)
	{
		$symbol = self::get_identifier_symbol($expr);
		if ($expr instanceof AccessingIdentifier && $symbol === null) {
			return true;
		}

		if ($symbol === null) {
			return false;
		}

		$decl = $symbol->declaration;
		if ($decl instanceof BaseVariableDeclaration) {
			return $decl->is_mutable;
		}

		if ($decl instanceof SuperVariableDeclaration) {
			return $decl->is_mutable;
		}

		if ($decl instanceof PropertyDeclaration) {
			return $decl->is_mutable;
		}

		return false;
	}

	public static function set_depends_to_unit_level(BaseDeclaration $decl): void
	{
		foreach ($decl->unknow_identifiers as $identifier) {
			$depends_decl = self::get_identifier_symbol($identifier)->declaration;
			if (!$depends_decl->is_unit_level) {
				$depends_decl->is_unit_level = true;
				self::set_depends_to_unit_level($depends_decl);
			}
		}
	}
}

// end
