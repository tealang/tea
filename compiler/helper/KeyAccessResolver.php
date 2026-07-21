<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class KeyAccessResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private $reduce_types;
	private SymbolDeclarationResolver $declaration_resolver;
	private UseResolver $use_resolver;
	private ClassBodyResolver $class_body_resolver;
	private ContainerLiteralResolver $container_literal_resolver;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		callable $reduce_types,
		SymbolDeclarationResolver $symbol_declaration_resolver,
		UseResolver $use_resolver,
		ClassBodyResolver $class_body_resolver,
		ContainerLiteralResolver $container_literal_resolver
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->reduce_types = $reduce_types;
		$this->declaration_resolver = $symbol_declaration_resolver;
		$this->use_resolver = $use_resolver;
		$this->class_body_resolver = $class_body_resolver;
		$this->container_literal_resolver = $container_literal_resolver;
	}

	public function infer_square_accessing(SquareAccessing $node): BaseType
	{
		$basing_type = ($this->infer_expression)($node->basing);
		if ($basing_type instanceof InvalidableType
			&& ($this->context->is_weakly_checking() || $this->context->allows_none_coalescing_key_access())) {
			$basing_type = $basing_type->valid_type;
		}
		$basing_type = TypeHelper::unwrap_excludable_type($basing_type);

		$infered = null;
		if ($basing_type instanceof ArrayType) {
			$infered = $basing_type->generic_type ?? TypeFactory::$_mixed;
		}
		elseif (PHPWeakPolicy::is_dynamic_top_type($basing_type)) {
			$infered = TypeFactory::$_mixed;
		}
		elseif ($this->context->is_weakly_checking() && $basing_type instanceof NoneType) {
			$infered = TypeFactory::$_mixed;
		}
		elseif ($basing_type instanceof UnionType) {
			if ($basing_type->is_all_array_types()) {
				$member_value_types = [];
				foreach ($basing_type->get_members() as $member) {
					$member_value_types[] = $member->generic_type;
				}

				$infered = ($this->reduce_types)($member_value_types);
			}
			elseif ($this->allow_weak_square_access_for_union_type($basing_type)) {
				$infered = TypeFactory::$_mixed;
			}
		}

		if ($infered === null) {
			if ($this->allow_weak_square_access_for_phpdoc_dict($node, $basing_type)) {
				return TypeFactory::$_mixed;
			}

			if ($this->allow_weak_square_access_for_phpdoc_virtual_type($node, $basing_type)) {
				return TypeFactory::$_mixed;
			}

			$type_name = ASTChecker::get_type_name($basing_type);
			$error = $this->context->new_syntax_error("Cannot use square accessing for type {$type_name}", $node);
			throw $error;
		}

		return $infered;
	}

	public function infer_key_accessing(KeyAccessing $node): BaseType
	{
		$key_expr = $node->key;
		$basing_type = ($this->infer_expression)($node->basing);
		if ($basing_type instanceof InvalidableType
			&& ($this->context->is_weakly_checking() || $this->context->allows_none_coalescing_key_access())) {
			$basing_type = $basing_type->valid_type;
		}
		$basing_type = TypeHelper::unwrap_excludable_type($basing_type);
		$key_type = $key_expr ? ($this->infer_expression)($key_expr) : null;
		if ($key_type instanceof BaseType) {
			$key_type = TypeHelper::unwrap_excludable_type($key_type);
		}

		if ($basing_type instanceof ArrayType) {
			if ($key_expr && $key_type instanceof ObjectType) {
				$type_name = ASTChecker::get_type_name($key_type);
				$error = $this->context->new_syntax_error("Index type for List accessing should be UInt, {$type_name} given", $key_expr);
				throw $error;
			}

			if ($key_expr and $key_type !== TypeFactory::$_uint and !$this->context->is_weakly_checking()) {
				$type_name = ASTChecker::get_type_name($key_type);
				$error = $this->context->new_syntax_error("Index type for List accessing should be UInt, {$type_name} given", $key_expr);
				throw $error;
			}

			$infered = $basing_type->generic_type ?? TypeFactory::$_mixed;
		}
		elseif ($basing_type instanceof DictType) {
			if ($key_expr === null) {
				$error = $this->context->new_syntax_error("Invalid accessing for Dict", $node);
				throw $error;
			}

			$this->assert_dict_key_type($key_type, $key_expr);
			$known_member_type = $this->get_known_dict_member_type($basing_type, $key_expr);
			if ($known_member_type === null && $this->allow_weak_dynamic_key_access_for_phpdoc_generic_array($node, $basing_type)) {
				return TypeFactory::$_mixed;
			}

			if ($known_member_type === null && $this->allow_weak_dynamic_dict_literal_member_access($node, $basing_type)) {
				return TypeFactory::$_mixed;
			}

			$infered = $known_member_type ?? $basing_type->generic_type ?? TypeFactory::$_mixed;
		}
		elseif ($this->is_array_access_type($basing_type)) {
			$key_expr and $this->assert_dict_key_type($key_type, $key_expr);
			$infered = TypeFactory::$_mixed;
		}
		elseif ($basing_type instanceof StringType) {
			if ($key_type !== TypeFactory::$_uint && $key_type !== TypeFactory::$_int) {
				if ($this->context->is_weakly_checking()) {
					$infered = TypeFactory::$_mixed;
					return $infered;
				}

				$error = $this->context->new_syntax_error("Index type for String should be Int/UInt, '{$key_type->name}' given", $node);
				throw $error;
			}

			$infered = TypeFactory::$_string;
		}
		elseif ($basing_type instanceof UnionType) {
			if ($basing_type->is_all_array_types()) {
				if ($key_type !== TypeFactory::$_uint && !($this->context->is_weakly_checking() && ($key_type instanceof AnyType || $key_type instanceof MixedType))) {
					$type_name = ASTChecker::get_type_name($key_type);
					$error = $this->context->new_syntax_error("Index type for List accessing should be UInt, {$type_name} given", $key_expr);
					throw $error;
				}
			}
			elseif ($basing_type->is_all_dict_types()) {
				$this->assert_dict_key_type($key_type, $key_expr);
			}
			elseif ($this->is_generalized_php_array_union($basing_type)) {
				$this->assert_generalized_php_array_key_type($key_type, $key_expr);
			}
			elseif ($this->context->allows_none_coalescing_key_access() && $basing_type->has_array_or_dict_type()) {
				// Allow nullable array/dict access on the left side of ?? and let coalescing handle none.
			}
			elseif ($this->context->is_weakly_checking() && $this->union_has_key_accessible_type($basing_type)) {
				// pass
			}
			elseif ($this->is_string_key_accessible_union($basing_type)) {
				$infered = TypeFactory::$_string;
				return $infered;
			}
			elseif ($this->allow_weak_dynamic_key_access($basing_type)) {
				$infered = TypeFactory::$_mixed;
				return $infered;
			}
			else {
				$type_name = ASTChecker::get_type_name($basing_type);
				$error = $this->context->new_syntax_error("Cannot use key accessing to type {$type_name}", $node);
				throw $error;
			}

			if ($this->allow_weak_dynamic_key_access_for_phpdoc_generic_array($node, $basing_type)) {
				return TypeFactory::$_mixed;
			}

			$member_value_types = [];
			foreach ($basing_type->get_members() as $member) {
				$member_value_types[] = $member->generic_type ?? TypeFactory::$_mixed;
			}

			$infered = ($this->reduce_types)($member_value_types);
		}
		elseif ($this->context->allows_none_coalescing_key_access() && $basing_type instanceof NoneType) {
			$infered = TypeFactory::$_none;
		}
		elseif ($this->context->is_weakly_checking() && $this->context->allows_php_soft_key_access() && $basing_type instanceof NoneType) {
			$infered = TypeFactory::$_mixed;
		}
		else {
			if ($this->allow_weak_dynamic_none_key_access($node, $basing_type)) {
				return TypeFactory::$_mixed;
			}

			if ($this->allow_weak_dynamic_key_access($basing_type)) {
				return TypeFactory::$_mixed;
			}
			$type_name = ASTChecker::get_type_name($basing_type);
			$error = $this->context->new_syntax_error("Cannot use key accessing to type {$type_name}", $node);
			throw $error;
		}

		return $infered;
	}

	public function is_string_key_accessible_union(UnionType $type): bool
	{
		foreach ($type->get_members() as $member) {
			if (!$member instanceof StringType) {
				return false;
			}
		}

		return true;
	}

	private function allow_weak_square_access_for_union_type(UnionType $type): bool
	{
		if (!$this->context->is_weakly_checking()) {
			return false;
		}

		if ($this->union_has_key_accessible_type($type)) {
			return true;
		}

		foreach ($type->get_members() as $member) {
			if ($member instanceof AnyType || $member instanceof MixedType) {
				return true;
			}
		}

		return false;
	}

	private function allow_weak_square_access_for_phpdoc_dict(SquareAccessing $node, BaseType $type): bool
	{
		if (!$this->context->is_weakly_checking() || !$type instanceof DictType) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return false;
		}

		$this->context->add_syntax_warning("PHPDoc declares Dict but value is used as list; degrading to dynamic access in PHP weak mode", $node);
		return true;
	}

	private function allow_weak_square_access_for_phpdoc_virtual_type(SquareAccessing $node, BaseType $type): bool
	{
		if (!$this->context->is_weakly_checking() || !$type instanceof TypeReference) {
			return false;
		}

		$type_decl = TypeHelper::get_type_symbol($type)->declaration ?? null;
		if (!$type_decl instanceof ClassKindredDeclaration || !$type_decl->is_virtual) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return false;
		}

		$this->context->add_syntax_warning("PHPDoc generic type is unresolved and value is used as list; degrading to dynamic access in PHP weak mode", $node);
		return true;
	}

	private function allow_weak_dynamic_key_access(BaseType $type): bool
	{
		if (!$this->context->is_weakly_checking()) {
			return false;
		}

		if (PHPWeakPolicy::is_dynamic_top_type($type)) {
			return true;
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if (PHPWeakPolicy::is_dynamic_top_type($member)) {
					return true;
				}
			}
		}

		return false;
	}

	private function union_has_key_accessible_type(UnionType $type): bool
	{
		foreach ($type->get_members() as $member) {
			if ($member instanceof ArrayType || $member instanceof DictType || $this->is_array_access_type($member)) {
				return true;
			}
		}

		return false;
	}

	private function is_generalized_php_array_union(UnionType $type): bool
	{
		$has_array = false;
		$has_dict = false;
		foreach ($type->get_members() as $member) {
			if ($member instanceof ArrayType) {
				$has_array = true;
			}
			elseif ($member instanceof DictType) {
				$has_dict = true;
			}
			else {
				return false;
			}
		}

		return $has_array && $has_dict;
	}

	private function allow_weak_dynamic_key_access_for_phpdoc_generic_array(KeyAccessing $node, BaseType $type): bool
	{
		if (!$this->context->is_weakly_checking() || $node->key === null || !$this->is_unknown_phpdoc_generic_array_key_access_type($type, $node->key)) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return false;
		}

		return true;
	}

	private function allow_weak_dynamic_dict_literal_member_access(KeyAccessing $node, DictType $type): bool
	{
		if (!$this->context->is_weakly_checking() || $node->key === null || $this->container_literal_resolver->get_literal_dict_key($node->key) === null) {
			return false;
		}

		if ($type->generic_type === null || $type->generic_type instanceof AnyType) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || $decl->declared_type !== null || $decl->noted_type !== null) {
			return false;
		}

		return true;
	}

	private function allow_weak_dynamic_none_key_access(KeyAccessing $node, BaseType $type): bool
	{
		if (!$this->context->is_weakly_checking() || !$type instanceof NoneType) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || $decl->declared_type !== null || $decl->noted_type !== null) {
			return false;
		}

		$this->context->add_syntax_warning("PHP weak dynamic value may be null during key access; degrading to dynamic access", $node);
		return true;
	}

	private function is_unknown_phpdoc_generic_array_key_access_type(BaseType $type, BaseExpression $key_expr): bool
	{
		if ($type instanceof DictType) {
			return $type->generic_type !== null && $this->get_known_dict_member_type($type, $key_expr) === null;
		}

		return $type instanceof UnionType
			&& $this->is_generalized_php_array_union($type)
			&& $this->get_known_union_dict_member_type($type, $key_expr) === null;
	}

	private function assert_generalized_php_array_key_type(BaseType $key_type, BaseExpression $key_expr): void
	{
		if (TypeHelper::is_dict_key_type($key_type) || $key_type === TypeFactory::$_uint || $key_type instanceof AnyType || $key_type instanceof MixedType) {
			return;
		}

		if ($this->allow_weak_generalized_php_array_key_type($key_type, $key_expr)) {
			return;
		}

		$type_name = ASTChecker::get_type_name($key_type);
		$error = $this->context->new_syntax_error("Key type for List|Dict accessing should be String/Int/UInt, {$type_name} given", $key_expr);
		throw $error;
	}

	private function allow_weak_generalized_php_array_key_type(BaseType $key_type, BaseExpression $key_expr): bool
	{
		if (!$this->context->is_weakly_checking()) {
			return false;
		}

		if (TypeHelper::is_dict_key_type($key_type) || $key_type === TypeFactory::$_uint || $key_type instanceof AnyType || $key_type instanceof MixedType) {
			return true;
		}

		if ($key_type instanceof UnionType) {
			foreach ($key_type->get_members() as $member) {
				if (!$this->allow_weak_generalized_php_array_key_type($member, $key_expr)) {
					return false;
				}
			}
			return true;
		}

		if ($key_type instanceof InvalidableType
			&& $this->allow_weak_generalized_php_array_key_type($key_type->valid_type, $key_expr)) {
			$this->context->add_syntax_warning("PHP weak array access uses Invalidable as key", $key_expr);
			return true;
		}

		if ($key_type instanceof FloatType) {
			$this->context->add_syntax_warning("PHP weak array access converts Float key to Int", $key_expr);
			return true;
		}

		return false;
	}

	private function is_array_access_type(BaseType $type)
	{
		$decl = null;
		if ($type instanceof TypeReference) {
			$symbol = TypeHelper::get_type_symbol($type);
			if ($symbol === null) {
				$symbol = $this->declaration_resolver->find_type_symbol_and_check_declaration($type);
				TypeHelper::set_type_symbol($type, $symbol);
			}

			$decl = $symbol ? $this->use_resolver->resolve_use_symbol_declaration($symbol, $type) : null;
			if ($decl instanceof ClassKindredDeclaration && !$this->context->is_classkindred_ready($decl)) {
				$this->class_body_resolver->preprocess_classkindred_declaration($decl);
			}
		}

		return $type instanceof AnyType
			|| $type instanceof MixedType
			|| ($decl instanceof ClassKindredDeclaration && $decl->has_feature(ClassFeature::ARRAY_ACCESS));
	}

	private function assert_dict_key_type(BaseType $key_type, BaseExpression $key_expr)
	{
		if ($key_type instanceof AnyType || $key_type instanceof MixedType) {
			return;
		}

		if (!TypeHelper::is_dict_key_type($key_type) && !$this->context->is_weakly_checking()) {
			$error = $this->context->new_syntax_error("Key type for Dict accessing should be String/Int, '{$key_type->name}' given", $key_expr);
			throw $error;
		}
	}

	private function get_known_dict_member_type(DictType $type, BaseExpression $key_expr): ?BaseType
	{
		$key = $this->container_literal_resolver->get_literal_dict_key($key_expr);

		return $key !== null ? ($type->known_member_types[$key] ?? null) : null;
	}

	private function get_known_union_dict_member_type(UnionType $type, ?BaseExpression $key_expr): ?BaseType
	{
		if ($key_expr === null) {
			return null;
		}

		$member_types = [];
		foreach ($type->get_members() as $member) {
			if (!$member instanceof DictType) {
				continue;
			}

			$member_type = $this->get_known_dict_member_type($member, $key_expr);
			if ($member_type !== null) {
				$member_types[] = $member_type;
			}
		}

		return $member_types ? ($this->reduce_types)($member_types) : null;
	}
}
