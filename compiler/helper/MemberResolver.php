<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class MemberResolver
{
	private CheckerContext $context;
	private $infer_expression;
	private TypeReferenceChecker $type_reference_checker;
	private CallableDeclarationResolver $callable_declaration_resolver;
	private ClassBodyResolver $class_body_resolver;
	private NameResolver $name_resolver;
	private SymbolDeclarationResolver $declaration_resolver;
	private ClassKindredLookupResolver $classkindred_lookup_resolver;
	private ValueTrustResolver $value_trust_resolver;

	public function __construct(
		CheckerContext $context,
		callable $infer_expression,
		TypeReferenceChecker $type_reference_checker,
		CallableDeclarationResolver $callable_declaration_resolver,
		ClassBodyResolver $class_body_resolver,
		NameResolver $name_resolver,
		SymbolDeclarationResolver $symbol_declaration_resolver,
		ClassKindredLookupResolver $classkindred_lookup_resolver,
		ValueTrustResolver $value_trust_resolver
	) {
		$this->context = $context;
		$this->infer_expression = $infer_expression;
		$this->type_reference_checker = $type_reference_checker;
		$this->callable_declaration_resolver = $callable_declaration_resolver;
		$this->class_body_resolver = $class_body_resolver;
		$this->name_resolver = $name_resolver;
		$this->declaration_resolver = $symbol_declaration_resolver;
		$this->classkindred_lookup_resolver = $classkindred_lookup_resolver;
		$this->value_trust_resolver = $value_trust_resolver;
	}

	public function infer_accessing_identifier(AccessingIdentifier $node, bool $ignore_none = false): BaseType
	{
		$basing_type = ($this->infer_expression)($node->basing);
		if ($basing_type instanceof UnionType) {
			if ($ignore_none || ($this->context->is_weakly_checking() && TypeHelper::is_nullable_type($basing_type))) {
				$basing_type = TypeHelper::to_non_nullable($basing_type);
			}

			if ($basing_type instanceof UnionType) {
				$infered_types = [];
				foreach ($basing_type->get_members() as $member_type) {
					try {
						$member = $this->require_accessing_identifier_declaration_for_type($member_type, $node);
						$infered_types[] = $this->get_member_type($member);
					}
					catch (\Throwable $e) {
						if ($this->context->is_weakly_checking()) {
							return TypeFactory::$_mixed;
						}
						$type_name = ASTChecker::get_type_name($basing_type);
						$error = $this->context->new_syntax_error("Cannot accessing '$node->name' to '$type_name'", $node);
						throw $error;
					}
				}

				if (count($infered_types) === 1) {
					return $infered_types[0];
				}
				return TypeFactory::create_union_type($infered_types);
			}
		}

		$member = $this->require_accessing_identifier_declaration($basing_type, $node);
		$this->value_trust_resolver->mark_member_value_trust($node, $member);

		switch ($member::KIND) {
			case MethodDeclaration::KIND:
				$this->context->is_checked($member) or $this->class_body_resolver->check_method_declaration($member);
				$infered = TypeFactory::create_callable_type($member->get_expressed_type(), $member->parameters);
				break;
			case FunctionDeclaration::KIND:
				$this->context->is_checked($member) or $this->callable_declaration_resolver->check_function_declaration($member);
				$infered = TypeFactory::create_callable_type($member->get_expressed_type(), $member->parameters);
				break;

			case MemberMappingDeclaration::KIND:
				if (!$member->is_property) {
					$error = $this->context->new_syntax_error("Cannot use the member mapping function '$member->name' without '()'", $node);
					throw $error;
				}
				// unbreak
			case PropertyDeclaration::KIND:
				$infered = TypeHelper::get_bound_type($member);
				break;

			case ClassConstantDeclaration::KIND:
			case EnumCaseDeclaration::KIND:
			case ObjectMember::KIND:

			case ClassDeclaration::KIND:
				$infered = $member->get_expressed_type();
				break;

			default:
				$error = $this->context->new_syntax_error("Unexpected expression", $node);
				throw $error;
		}

		if ($this->context->is_weakly_checking() && ($infered instanceof TypeReference || $infered instanceof UnionType)) {
			$this->type_reference_checker->check_type($infered, $node);
		}

		return $infered;
	}

	public function require_accessing_identifier_declaration(BaseType $basing_type, AccessingIdentifier $node): IDeclaration
	{
		if ($basing_type instanceof InvalidableType && $basing_type->sentinel instanceof LiteralNone) {
			if (!$this->context->is_weakly_checking() && !$node->nullsafe) {
				$type_name = ASTChecker::get_type_name($basing_type);
				$error = $this->context->new_syntax_error("Cannot accessing '$node->name' to '$type_name'", $node);
				throw $error;
			}

			$basing_type = $basing_type->valid_type;
		}
		elseif ($basing_type instanceof ExcludableType) {
			$basing_type = $basing_type->base_type;
		}

		if ($basing_type instanceof UnionType) {
			$resolved_decl = null;
			foreach ($basing_type->get_members() as $member_type) {
				if ($member_type instanceof NoneType) {
					if ($this->context->is_weakly_checking() || $node->nullsafe) {
						continue;
					}

					$type_name = ASTChecker::get_type_name($basing_type);
					$error = $this->context->new_syntax_error("Cannot accessing '$node->name' to '$type_name'", $node);
					throw $error;
				}

				$resolved_decl ??= $this->require_accessing_identifier_declaration_for_type($member_type, $node);
			}

			if ($resolved_decl !== null) {
				return $resolved_decl;
			}

			$type_name = ASTChecker::get_type_name($basing_type);
			$error = $this->context->new_syntax_error("Cannot accessing '$node->name' to '$type_name'", $node);
			throw $error;
		}

		if ($basing_type instanceof MetaType) {
			$this->attach_symbol_for_metatype_accessing_identifier($node, $basing_type);
		}
		elseif ($basing_type instanceof BaseType) {
			$this->attach_symbol_for_basetype_accessing_identifier($node, $basing_type);
		}
		elseif ($basing_type instanceof Identifiable) {
			$basing_decl = $this->classkindred_lookup_resolver->get_actual_class_declaration_for_metatype_expr($basing_type);
			$this->name_resolver->set_symbol_for_symbolic_node($node, $this->require_class_member_symbol($basing_decl, $node));
			if (!$this->is_instance_accessable($node->basing, $this->name_resolver->get_symbol_for_symbolic_node($node)->declaration)) {
				$error = $this->context->new_syntax_error("Cannot access private/protected members", $node);
				throw $error;
			}
		}
		else {
			$type_name = ASTChecker::get_type_name($basing_type);
			$error = $this->context->new_syntax_error("Invalid accessable type '$type_name'", $node->basing);
			throw $error;
		}

		return $this->name_resolver->get_symbol_for_symbolic_node($node)->declaration;
	}

	public function require_class_member_symbol(ClassKindredDeclaration $class_decl, AccessingIdentifier $node, bool $allow_weak_virtual_member = true): Symbol
	{
		$name = $node->name;
		if ($node->is_static && $name === _CLASS) {
			[, $symbol] = $this->create_virtual_class_constant($name, TypeFactory::$_text, $class_decl, $node);
			return $symbol;
		}

		$symbol = $node->is_invoking()
			? $this->find_member_symbol_in_class_declaration($class_decl, $name)
			: $this->find_property_symbol_in_class_declaration($class_decl, $name);
		if ($symbol === null) {
			$symbol = $this->try_create_runtime_member_symbol($class_decl, $node)
				?? $this->try_create_virtual_member_symbol($class_decl, $node);
			if ($symbol === null && $allow_weak_virtual_member) {
				if ($node->is_invoking()) {
					[, $symbol] = $this->create_virtual_method($name, $class_decl, $node);
				}
				else {
					[, $symbol] = $this->create_virtual_property($name, $class_decl, $node);
				}
			}
			if ($symbol === null && $this->context->is_weakly_checking() && !$allow_weak_virtual_member) {
				$error = $this->context->new_syntax_error("Cannot accessing '{$name}' to '{$class_decl->name}'", $node);
				throw $error;
			}
			if ($symbol === null) {
				$error = $this->context->new_syntax_error("Member '{$name}' not found in '{$class_decl->name}'", $node);
				throw $error;
			}

			$symbol->declaration->is_static = $node->is_static;
		}

		return $symbol;
	}

	public function find_member_symbol_in_class_declaration(ClassKindredDeclaration $classkindred, string $name): ?Symbol
	{
		if (!$this->context->is_classkindred_ready($classkindred)) {
			$this->class_body_resolver->preprocess_classkindred_declaration($classkindred);
		}

		$symbol = ASTHelper::get_aggregated_members($classkindred)[$name] ?? null;
		if ($symbol) {
			$decl = $symbol->declaration;
			if (!$this->context->is_checked($decl)) {
				$temp_program = $this->context->get_program();
				$actual_class = $decl->belong_block;
				$this->context->set_program($actual_class->program);

				$this->class_body_resolver->check_class_member_declaration($decl);

				$this->context->set_program($temp_program);
			}
		}

		return $symbol;
	}

	private function get_member_type(IDeclaration $member): BaseType
	{
		switch ($member::KIND) {
			case MethodDeclaration::KIND:
			case FunctionDeclaration::KIND:
				return TypeFactory::create_callable_type($member->get_expressed_type(), $member->parameters);

			case MemberMappingDeclaration::KIND:
				if (!$member->is_property) {
					return TypeFactory::$_mixed;
				}
				// unbreak
			case PropertyDeclaration::KIND:
				return TypeHelper::get_bound_type($member);

			case ClassConstantDeclaration::KIND:
			case EnumCaseDeclaration::KIND:
			case ObjectMember::KIND:
			case ClassDeclaration::KIND:
				return $member->get_expressed_type();

			default:
				return TypeFactory::$_mixed;
		}
	}

	private function require_accessing_identifier_declaration_for_type(BaseType $basing_type, AccessingIdentifier $node): IDeclaration
	{
		$basing_type = TypeHelper::unwrap_excludable_type($basing_type);
		if ($basing_type instanceof MetaType) {
			$this->attach_symbol_for_metatype_accessing_identifier($node, $basing_type);
		}
		elseif ($basing_type instanceof BaseType) {
			$this->attach_symbol_for_basetype_accessing_identifier($node, $basing_type);
		}
		elseif ($basing_type instanceof Identifiable) {
			$basing_decl = $this->classkindred_lookup_resolver->get_actual_class_declaration_for_metatype_expr($basing_type);
			$this->name_resolver->set_symbol_for_symbolic_node($node, $this->require_class_member_symbol($basing_decl, $node));
		}
		else {
			$error = $this->context->new_syntax_error("Invalid accessable type", $node->basing);
			throw $error;
		}

		return $this->name_resolver->get_symbol_for_symbolic_node($node)->declaration;
	}

	private function attach_symbol_for_basetype_accessing_identifier(AccessingIdentifier $node, BaseType $basing_type): void
	{
		$basing_symbol = $this->name_resolver->get_symbol_for_symbolic_node($basing_type);
		if ($basing_symbol === null && $basing_type instanceof TypeReference) {
			$basing_symbol = $this->declaration_resolver->find_type_symbol_and_check_declaration($basing_type);
			$this->name_resolver->set_symbol_for_symbolic_node($basing_type, $basing_symbol);
		}
		elseif ($basing_symbol === null) {
			$basing_symbol = $this->name_resolver->find_symbol_for_type($basing_type);
			$this->name_resolver->set_symbol_for_symbolic_node($basing_type, $basing_symbol);
		}
		if ($basing_symbol === null && $this->allow_weak_virtual_basing_class($basing_type, $node)) {
			[, $basing_symbol] = $this->create_virtual_class($basing_type->name, $node);
			$this->name_resolver->set_symbol_for_symbolic_node($basing_type, $basing_symbol);
		}
		if ($basing_symbol === null) {
			$type_name = ASTChecker::get_type_name($basing_type);
			$error = $this->context->new_syntax_error("Cannot accessing '{$node->name}' to '{$type_name}'", $node);
			throw $error;
		}

		$basing_decl = $basing_symbol->declaration;
		if (!$basing_decl instanceof ClassKindredDeclaration) {
			$basing_decl = $this->classkindred_lookup_resolver->filter_classkindred_declaration($basing_decl, $basing_type, $node);
		}

		$allow_weak_virtual_member = $this->allow_weak_virtual_member_for_type($basing_type, $basing_decl, $node);
		$symbol = $this->require_class_member_symbol($basing_decl, $node, $allow_weak_virtual_member);
		$this->name_resolver->set_symbol_for_symbolic_node($node, $symbol);
	}

	private function allow_weak_virtual_basing_class(BaseType $type, AccessingIdentifier $node): bool
	{
		return $type instanceof AnyType
			|| $type instanceof MixedType
			|| ($this->context->is_weakly_checking()
				&& ($type instanceof ObjectType || ($type instanceof StringType && $node->is_static)));
	}

	private function allow_weak_virtual_member_for_type(BaseType $type, ClassKindredDeclaration $class_decl, AccessingIdentifier $node): bool
	{
		if ($type instanceof AnyType || $type instanceof MixedType) {
			return true;
		}

		if (!$this->context->is_weakly_checking()) {
			return false;
		}

		if ($class_decl->is_virtual || $type instanceof AnyType || $type instanceof MixedType || $type instanceof ObjectType || $type instanceof TypeReference) {
			return true;
		}

		if ($class_decl instanceof InterfaceDeclaration || $class_decl instanceof TraitDeclaration) {
			return true;
		}

		return $type instanceof StringType && $node->is_static;
	}

	private function attach_symbol_for_metatype_accessing_identifier(AccessingIdentifier $node, MetaType $basing_type): void
	{
		$basing_decl = TypeHelper::get_type_symbol($basing_type->generic_type)->declaration;
		$symbol = $this->require_class_member_symbol($basing_decl, $node);

		$this->name_resolver->set_symbol_for_symbolic_node($node, $symbol);
		$node_declaration = $symbol->declaration;
		if ($node_declaration instanceof ClassConstantDeclaration) {
			$node_declaration->is_static = true;
		}
		if (!$node_declaration->is_static) {
			$error = $this->context->new_syntax_error("Invalid to accessing a non-static member", $node);
			throw $error;
		}

		if (!$this->is_static_accessable($node->basing, $node_declaration)) {
			$error = $this->context->new_syntax_error("Cannot accessing the private/protected members", $node);
			throw $error;
		}
	}

	private function is_instance_accessable(BaseExpression $expr, IClassMemberDeclaration $member): bool
	{
		if ($member->modifier === _PRIVATE) {
			$accessable = $expr instanceof PlainIdentifier && ASTHelper::get_identifier_symbol($expr) === $member->belong_block->this_object_symbol;
		}
		elseif ($member->modifier === _PROTECTED) {
			$accessable = $expr instanceof PlainIdentifier && ($expr->name === _THIS || $expr->name === _SUPER);
		}
		else {
			$accessable = true;
		}

		return $accessable;
	}

	private function is_static_accessable(BaseExpression $expr, IClassMemberDeclaration $member): bool
	{
		if ($member->modifier === _PRIVATE) {
			$accessable = $expr instanceof PlainIdentifier && ASTHelper::get_identifier_symbol($expr) === $member->belong_block->this_class_symbol;
		}
		elseif ($member->modifier === _PROTECTED) {
			$accessable = $expr instanceof PlainIdentifier && ($expr->name === _THIS || $expr->name === _SUPER);
		}
		else {
			$accessable = true;
		}

		return $accessable;
	}

	private function find_property_symbol_in_class_declaration(ClassKindredDeclaration $classkindred, string $name): ?Symbol
	{
		if (!$this->context->is_classkindred_ready($classkindred)) {
			$this->class_body_resolver->preprocess_classkindred_declaration($classkindred);
		}

		$property_key = ClassKindredDeclaration::get_property_symbol_key($name);
		if (isset(ASTHelper::get_aggregated_members($classkindred)[$property_key])) {
			return $this->find_member_symbol_in_class_declaration($classkindred, $property_key);
		}

		return $this->find_member_symbol_in_class_declaration($classkindred, $name);
	}

	private function try_create_runtime_member_symbol(ClassKindredDeclaration $class_decl, AccessingIdentifier $node): ?Symbol
	{
		if (!$this->context->is_weakly_checking()) {
			return null;
		}

		$runtime_class = $this->get_runtime_class_name($class_decl);
		if ($runtime_class === null) {
			return null;
		}

		if (method_exists($runtime_class, $node->name)) {
			[$decl, $symbol] = $this->create_virtual_method($node->name, $class_decl, $node);
			try {
				$decl->is_static = (new \ReflectionMethod($runtime_class, $node->name))->isStatic();
			}
			catch (\ReflectionException $e) {
				// Keep the virtual method metadata minimal when reflection cannot inspect it.
			}

			return $symbol;
		}

		if (!$node->is_invoking() && property_exists($runtime_class, $node->name)) {
			[$decl, $symbol] = $this->create_virtual_property($node->name, $class_decl, $node);
			try {
				$decl->is_static = (new \ReflectionProperty($runtime_class, $node->name))->isStatic();
			}
			catch (\ReflectionException $e) {
				// Keep the virtual property metadata minimal when reflection cannot inspect it.
			}

			return $symbol;
		}

		return null;
	}

	private function get_runtime_class_name(ClassKindredDeclaration $class_decl): ?string
	{
		$ns_identifier = $class_decl->ns ?? $class_decl->program?->ns;
		$ns = $ns_identifier?->get_namepath() ?? [];
		$fqcn = ($ns ? join('\\', $ns) . '\\' : '') . $class_decl->name;
		if ($fqcn !== '' && (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn))) {
			return $fqcn;
		}

		return null;
	}

	private function try_create_virtual_member_symbol(ClassKindredDeclaration $class_decl, AccessingIdentifier $node): ?Symbol
	{
		$symbol = null;
		if ($node->is_invoking()) {
			if ($this->can_use_feature(ClassFeature::MAGIC_CALL, $class_decl)) {
				[, $symbol] = $this->create_virtual_method($node->name, $class_decl, $node);
			}
		}
		elseif ($node->is_assigning()) {
			if ($this->can_use_feature(ClassFeature::MAGIC_SET, $class_decl)) {
				[, $symbol] = $this->create_virtual_property($node->name, $class_decl, $node);
			}
		}
		else {
			if ($this->can_use_feature(ClassFeature::MAGIC_GET, $class_decl)) {
				[, $symbol] = $this->create_virtual_property($node->name, $class_decl, $node);
			}
		}

		return $symbol;
	}

	private function can_use_feature(ClassFeature $feature, ClassKindredDeclaration $decl): bool
	{
		if ($decl->is_virtual || $decl->has_feature($feature)) {
			$can = true;
		}
		elseif ($this->context->is_weakly_checking()) {
			$can = $decl instanceof TraitDeclaration || in_array($decl->name, PHPChecker::DYNAMIC_CLASS_NAMES);
		}
		else {
			$can = false;
		}

		return $can;
	}

	private function create_virtual_class(string $name, Node $node): array
	{
		$factory = $this->context->get_factory();
		if ($factory === null) {
			$error = $this->context->new_syntax_error("Cannot create virtual class '{$name}' without ASTFactory", $node);
			throw $error;
		}

		return $factory->create_virtual_class($name, $this->context->get_program());
	}

	private function create_virtual_method(string $name, ClassKindredDeclaration $class_decl, Node $node): array
	{
		$factory = $this->context->get_factory();
		if ($factory === null) {
			$error = $this->context->new_syntax_error("Cannot create virtual method '{$name}' without ASTFactory", $node);
			throw $error;
		}

		return $factory->create_virtual_method($name, $class_decl);
	}

	private function create_virtual_property(string $name, ClassKindredDeclaration $class_decl, Node $node): array
	{
		$factory = $this->context->get_factory();
		if ($factory === null) {
			$error = $this->context->new_syntax_error("Cannot create virtual property '{$name}' without ASTFactory", $node);
			throw $error;
		}

		return $factory->create_virtual_property($name, $class_decl);
	}

	private function create_virtual_class_constant(string $name, BaseType $type, ClassKindredDeclaration $class_decl, Node $node): array
	{
		$factory = $this->context->get_factory();
		if ($factory === null) {
			$error = $this->context->new_syntax_error("Cannot create virtual class constant '{$name}' without ASTFactory", $node);
			throw $error;
		}

		return $factory->create_virtual_class_constant($name, $type, $class_decl);
	}
}
