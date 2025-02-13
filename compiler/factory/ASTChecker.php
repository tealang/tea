<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ASTChecker
{
	const NS_SEPARATOR = TeaParser::NS_SEPARATOR;

	protected $is_weakly_checking = false;

	/**
	 * @var ASTFactory
	 */
	protected $factory;

	/**
	 * the builtin unit
	 * @var Unit
	 */
	private $builtin_unit;

	/**
	 * current unit
	 * @var Unit
	 */
	private $unit;

	/**
	 * current program
	 * @var Program
	 */
	private $program;

	/**
	 * current block
	 * @var IBlock
	 */
	private $block;

	private $context_function;

	private static $builtin_checker_instance;
	private static $native_checker_instance;
	private static $normal_checker_instances = [];

	public static function get_checker(Program $program)
	{
		if ($program->is_native) {
			return self::$native_checker_instance;
		}

		return $program->unit === null
			? self::$builtin_checker_instance
			: self::$normal_checker_instances[$program->unit->name];
	}

	public static function get_native_checker()
	{
		return self::$native_checker_instance;
	}

	public static function init_checkers(Unit $main_unit, Unit $builtin_unit = null)
	{
		self::$normal_checker_instances = [];

		// for builtin unit
		if ($builtin_unit) {
			self::$normal_checker_instances[$builtin_unit->name] = new ASTChecker($builtin_unit);
		}

		$main_checker = new ASTChecker($main_unit, $builtin_unit);

		self::$builtin_checker_instance = $main_checker;
		self::$native_checker_instance = new PHPChecker($main_unit, $builtin_unit);

		self::$normal_checker_instances[$main_unit->name] = $main_checker;

		foreach ($main_unit->use_units as $unit) {
			self::init_checker_for_unit($unit, $builtin_unit); // direct
			foreach ($unit->use_units as $indirect_unit) {
				self::init_checker_for_unit($indirect_unit, $builtin_unit);
			}
		}
	}

	private static function init_checker_for_unit(Unit $unit, Unit $builtin_unit)
	{
		if (!isset(self::$normal_checker_instances[$unit->name])) {
			self::$normal_checker_instances[$unit->name] = new ASTChecker($unit, $builtin_unit);
		}
	}

	public function __construct(Unit $unit, Unit $builtin_unit = null)
	{
		$this->unit = $unit;
		$this->builtin_unit = $builtin_unit;
		$this->factory = $unit->factory;
	}

	public function link_declarations(Program $program)
	{
		$this->program = $program;

		foreach ($program->declarations as $decl) {
			if ($decl instanceof ClassKindredDeclaration) {
				$decl->is_linked || $this->link_classkindred_declaration($decl);
			}
			else {
				$this->resolve_unknown_identifiers($decl);
			}
		}

		$program->initializer && $this->resolve_unknown_identifiers($program->initializer);
	}

	private function resolve_unknown_identifiers(IDeclaration $host_decl)
	{
		foreach ($host_decl->unknow_identifiers as $identifier) {
			if ($identifier->symbol !== null) {
				// eg. identifiers that in foreach arguments
				continue;
			}

			$symbol = $this->find_symbol_for_plain_identifier($identifier);
			if ($symbol === null) {
				if ($this->is_weakly_checking && !$identifier instanceof VariableIdentifier) {
					$symbol = $this->try_create_virtual_symbol($identifier);
				}

				if ($symbol === null) {
					throw $this->new_syntax_error("Symbol of '{$identifier->name}' not found", $identifier);
				}
			}

			$depends = $symbol->declaration;
			if ($depends instanceof UseDeclaration) {
				$source = $depends->source_declaration;
				if ($source) {
					$symbol->declaration = $source;
					$symbol->using = $depends;
					$host_decl->append_use_declaration($depends);
				}
				else {
					$symbol = $this->try_create_virtual_symbol($identifier);
				}
			}
			elseif ($symbol->using) {
				$host_decl->append_use_declaration($symbol->using);
			}

			$identifier->symbol = $symbol;
		}
	}

	private function try_create_virtual_symbol(PlainIdentifier $identifier)
	{
		$name = $identifier->name;
		if ($identifier->is_invoking()) {
			[$decl, $symbol] = $identifier->is_instancing()
				? $this->factory->create_virtual_class($name, $this->program)
				: $this->factory->create_virtual_function($name, $this->program);
		}
		elseif ($identifier->is_accessing() || $identifier instanceof ClassKindredIdentifier) {
			[$decl, $symbol] = $this->factory->create_virtual_class($name, $this->program);
		}
		else {
			// treat others as constant identifiers
			[$decl, $symbol] = $this->factory->create_virtual_constant($name);
		}

		return $symbol;
	}

	public function check_program(Program $program)
	{
		if ($program->is_checked) return;
		$program->is_checked = true;

		$this->program = $program;

		// foreach ($program->use_targets as $target) {
		// 	$this->check_use_target($target);
		// }

		foreach ($program->declarations as $node) {
			$this->check_declaration($node);
		}

		if ($program->initializer) {
			$this->check_declaration($program->initializer);
		}
	}

	public function check_all_usings(Program $program)
	{
		$this->program = $program;
		foreach ($program->use_targets as $target) {
			$this->check_use_target($target);
		}
	}

	protected function check_use_target(UseDeclaration $node)
	{
		$unit = $this->get_uses_unit_declaration($node->ns);
		if ($unit) {
			$this->link_source_declaration_for_use($node, $unit);
		}
		elseif (!$this->is_weakly_checking) {
			throw $this->new_syntax_error("Package '{$node->ns->uri}' not found", $node->ns);
		}
	}

	private function check_declaration(IDeclaration $decl)
	{
		if ($decl->is_checked) {
			return $decl;
		}

		$decl_program = $decl->program ?? null;
		$temp_program = $this->program;
		$decl_program and $this->program = $decl_program;

		switch ($decl::KIND) {
			case FunctionDeclaration::KIND:
				$this->check_function_declaration($decl);
				break;

			case BuiltinTypeClassDeclaration::KIND:
			case ClassDeclaration::KIND:
			case IntertraitDeclaration::KIND:
			case TraitDeclaration::KIND:
			case InterfaceDeclaration::KIND:
				$decl->is_checked || $this->check_classkindred_declaration($decl);
				break;

			case ConstantDeclaration::KIND:
				$this->check_constant_declaration($decl);
				break;
			case SuperVariableDeclaration::KIND:
			case VariableDeclaration::KIND:
			case ParameterDeclaration::KIND:
				$this->check_variable_declaration($decl);
				break;

			default:
				$kind = $decl::KIND;
				throw $this->new_syntax_error("Unexpect declaration kind: '{$kind}'", $decl);
		}

		$decl_program and $this->program = $temp_program;
	}

	private function check_class_member_declaration(IClassMemberDeclaration $node)
	{
		switch ($node::KIND) {
			case MaskedDeclaration::KIND:
				$this->check_masked_declaration($node);
				break;

			case MethodDeclaration::KIND:
				$this->check_method_declaration($node);
				break;

			case PropertyDeclaration::KIND:
			case ObjectMember::KIND:
				$this->check_property_declaration($node);
				break;

			case ClassConstantDeclaration::KIND:
				$this->check_class_constant_declaration($node);
				break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unexpect class/interface member declaration kind: '{$kind}'", $node);
		}
	}

	private function get_and_check_hinted_type(IDeclaration|AnonymousFunction $node)
	{
		$noted = $node->noted_type;
		$declared = $node->declared_type;

		if ($noted) {
			$this->check_type($noted, $node);
		}

		if ($declared) {
			$this->check_type($declared, $node);
			// if ($noted and !$declared->is_accept_type($noted)) {
			// 	$noted_name = self::get_type_name($noted);
			// 	$declared_name = self::get_type_name($declared);
			// 	throw $this->new_syntax_error("The noted type '{$noted_name}' is incompatible with the declared '{$declared_name}'", $node);
			// }
		}

		$result_type = $noted ?? $declared;
		return $result_type;
	}

	private function check_constant_declaration(IConstantDeclaration $node)
	{
		if ($node->is_checked) return;
		$node->is_checked = true;

		$value = $node->value;
		$hinted = $this->get_and_check_hinted_type($node);

		// no value, it should be in declare mode
		if ($value === null) {
			if (!$hinted) {
				throw $this->new_syntax_error("Type hinting for constant declaration '{$node->name}' required", $node);
			}
			return;
		}

		// has value
		$infered = $this->infer_expression($value);
		if (!$infered) {
			throw $this->new_syntax_error("Type hinting for constant declaration '{$node->name}' required", $node);
		}

		$this->assert_compile_time_value_for($node);

		if ($hinted) {
			$this->assert_type_compatible($hinted, $infered, $node->value);
		}

		$node->infered_type = $infered;
	}

	private function assert_compile_time_value_for(IValuedDeclaration $node)
	{
		$value = $node->value;

		if (!$this->is_const_expr($value) and !$node instanceof ObjectMember) {
			throw $this->new_syntax_error("Invalid initial value expression", $value);
		}

		if ($value instanceof BinaryOperation) {
			if ($value->operator->is(OPID::CONCAT)) {
				$left_type = $this->infer_expression($value->left);
				if ($left_type instanceof ArrayType) {
					throw $this->new_syntax_error("Array concat operation cannot use as a compile-time value", $value);
				}
			}
			// elseif ($value->operator->is(OPID::MERGE)) {
			// 	throw $this->new_syntax_error("Dict merge operation cannot use for constant value", $value);
			// }
		}
	}

	private function is_const_expr(BaseExpression $node): bool
	{
		// if ($node instanceof ILiteral || $node instanceof ConstantIdentifier) {
		// 	$result = true;
		// }
		// else
		if ($node instanceof Identifiable) {
			$decl = $node->symbol->declaration;
			$result = $decl instanceof IConstantDeclaration || $decl instanceof ClassKindredDeclaration;
		}
		elseif ($node instanceof BinaryOperation) {
			$result = $this->is_const_expr($node->left) && $this->is_const_expr($node->right);
		}
		elseif ($node instanceof PrefixOperation) {
			$result = $this->is_const_expr($node->expression);
		}
		elseif ($node instanceof ArrayExpression) {
			$result = true;
			foreach ($node->items as $item) {
				if (!$this->is_const_expr($item)) {
					$result = false;
					break;
				}
			}
		}
		elseif ($node instanceof DictExpression) {
			$result = true;
			foreach ($node->items as $item) {
				if (!$this->is_const_expr($item->key) || !$this->is_const_expr($item->value)) {
					$result = false;
					break;
				}
			}
		}
		// elseif ($node instanceof XTag) {
		// 	$result = $node->is_const_value;
		// }
		else {
			$result = $node->is_const_value ?? false;
		}

		return $result;
	}

	private function check_variable_declaration(BaseVariableDeclaration $node)
	{
		$node->is_checked = true;

		$infered = $node->value ? $this->infer_expression($node->value) : null;

		$this->set_variable_kindred_declaration_type($node, $infered);
	}

	private function set_variable_kindred_declaration_type(IDeclaration $node, ?IType $infered)
	{
		$hinted = $this->get_and_check_hinted_type($node);
		if ($hinted) {
			if ($infered and !$infered instanceof NoneType) {
				$this->assert_type_compatible($hinted, $infered, $node->value);
			}

			// use the hinted as infered, because is declaration
			$infered = $hinted;
		}
		elseif ($infered === TypeFactory::$_uint && $node->value instanceof LiteralInteger) {
			// set infered type to Int when value is Integer literal
			// $infered = TypeFactory::$_int;
		}
		elseif ($infered instanceof NoneType) {
			$infered = TypeFactory::$_any;
		}
		// elseif ($infered === null || $infered instanceof NoneType) {
		// 	$infered = TypeFactory::$_any;
		// }

		$node->infered_type = $infered;
	}

	private function check_parameters_for_callable_declaration(ICallableDeclaration $callable)
	{
		$this->check_parameters_for_node($callable);
	}

	private function check_parameters_for_node($node)
	{
		foreach ($node->parameters as $parameter) {
			$this->check_variable_declaration($parameter);
			if ($parameter->value !== null) {
				$this->assert_compile_time_value_for($parameter);
			}
		}
	}

	// private function check_callback_protocol(CallbackProtocol $node)
	// {
	// 	$node->is_checked = true;

	//	$hinted = $this->get_and_check_hinted_type($node);
	// 	$node->infered_type = $hinted ?? TypeFactory::$_void;

	// 	$this->check_parameters_for_callable_declaration($node);
	// }

	private function check_masked_declaration(MaskedDeclaration $node)
	{
		$node->parameters && $this->check_parameters_for_callable_declaration($node);
		$masked = $node->body;

		// maybe need render, so check first
		$temp_block = $this->block;
		$this->block = $node;
		$infered = $this->infer_expression($masked);
		$this->block = $temp_block;

		$hinted = $this->get_and_check_hinted_type($node);
		if ($hinted) {
			if ($infered and !$infered instanceof NoneType) {
				$this->assert_type_compatible($hinted, $infered, $node);
			}
			else {
				$infered = $hinted;
			}
		}

		$node->infered_type = $infered;

		if ($masked instanceof CallExpression) {
			$this->process_masked($node);
		}
		else {
			// the this, do not need any process
		}
	}

	private function process_masked(MaskedDeclaration $node)
	{
		$i = 0;
		$parameters_map = [_THIS => $i++];

		$parameters = $node->parameters ?? [];

		foreach ($parameters as $item) {
			$parameters_map[$item->name] = $i++;
		}

		$masked = $node->body;
		foreach ($masked->arguments as $dest_idx => $item) {
			if ($item instanceof PlainIdentifier) {
				if (!isset($parameters_map[$item->name])) {
					$this->infer_plain_identifier($item);
					if ($item->symbol->declaration instanceof ConstantDeclaration) {
	 					$node->arguments_map[$dest_idx] = $item;
	 					continue;
					}

					throw $this->new_syntax_error("Identifier '{$item->name}' not defined in MaskedDeclaration", $item);
				}

				// the argument from masking call
	 			$node->arguments_map[$dest_idx] = $parameters_map[$item->name];
	 			continue;
			}

			// the literal value for argument
			// if ($item instanceof ILiteral || ($item instanceof PrefixOperation && $item->expression instanceof ILiteral)) {
			if ($item->is_const_value || ($item instanceof PrefixOperation && $item->expression->is_const_value)) {
				$node->arguments_map[$dest_idx] = $item;
				continue;
			}
			else {
				throw $this->new_syntax_error("Unexpect expression in MaskedDeclaration", $item);
			}
		}
	}

	private function infer_anonymous_function(AnonymousFunction $node)
	{
		// check for use variables
		foreach ($node->unknow_identifiers as $identifier) {
			if (!$identifier->symbol) {
				$this->infer_plain_identifier($identifier);
			}

			if ($identifier->symbol->declaration instanceof IVariableDeclaration) {
				if ($identifier->name === _THIS || $identifier->name === _SUPER) {
					throw $this->new_syntax_error("'{$identifier->name}' cannot use in lambda functions", $node);
				}

				// for lambda 'use' in php
				$name = $identifier->name;
				$param = new ParameterDeclaration($name);
				$param->is_inout = true;
				$node->using_params[$name] = $param;
			}
		}

		$this->check_function_kindred($node);

		return TypeFactory::create_callable_type($node->get_type(), $node->parameters);
	}

	private function check_function_kindred(IScopeBlock $node)
	{
		$this->check_parameters_for_callable_declaration($node);

		$hinted = $this->get_and_check_hinted_type($node);
		if ($hinted instanceof BaseType && $hinted->name === _TYPE_SELF) {
			$decl = $node->belong_block;
			$hinted = $decl->typing_identifier;
		}

		$infered = $node->body ? $this->infer_function_body($node, $hinted) : null;

		$node->infered_type =  $infered ?? $hinted ?? TypeFactory::$_void;
	}

	private function infer_function_body(IScopeBlock $node, ?IType $hinted)
	{
		$prev_func = $this->context_function;
		$this->context_function = $node; // for find 'super' in methods

		if (is_array($node->body)) {
			$infered = $this->infer_block($node);
		}
		else {
			$infered = $this->infer_single_expression_block($node);
		}

		if ($hinted) {
			if ($infered !== null) {
				if (!$hinted->is_accept_type($infered) && !$this->is_weakly_checking) {
					$infered_name = self::get_type_name($infered);
					$hinted_name = self::get_type_name($hinted);
					throw $this->new_syntax_error("The infered return type '{$infered_name}' is incompatible with the hinted '{$hinted_name}'", $node);
				}
			}
			elseif ($hinted !== TypeFactory::$_void && $hinted !== TypeFactory::$_generator) {
				throw $this->new_syntax_error("Function required a return type '{$hinted->name}'", $node);
			}
		}

		$this->context_function = $prev_func;
		return $infered;
	}

	protected function check_function_declaration(FunctionDeclaration $node)
	{
		if ($node->is_checked) return;
		$node->is_checked = true;

		$this->check_function_kindred($node);
	}


	protected function check_method_declaration(MethodDeclaration $node)
	{
		if ($node->is_checked) return;
		$node->is_checked = true;

		$this->check_function_kindred($node);
	}

	private function check_property_declaration(PropertyDeclaration $node)
	{
		if ($node->value !== null) {
			$infered = $this->infer_expression($node->value);
			$this->assert_compile_time_value_for($node);
		}
		else {
			$infered = null;
		}

		$this->set_variable_kindred_declaration_type($node, $infered);
	}

	private function check_class_constant_declaration(ClassConstantDeclaration $node)
	{
		$infered = isset($node->value) ? $this->infer_expression($node->value) : null;

		$hinted = $this->get_and_check_hinted_type($node);
		if ($hinted) {
			if ($infered and !$infered instanceof NoneType) {
				$infered && $this->assert_type_compatible($hinted, $infered, $node->value);
			}
		}

		$node->infered_type = $infered ?? $hinted ?? TypeFactory::$_any;
	}

	private function link_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$this->resolve_unknown_identifiers($node);

		// when it is currently a class, including inherited classes or implemented interfaces
		// when it is currently an interface, including inherited interfaces
		if ($node->extends) {
			$this->preprocess_bases_for_classkindred_declaration($node);
		}

		$node->trait_members = $this->dig_trait_members_for($node);

		$node->aggregated_members = array_merge($node->trait_members, $node->symbols);

		if ($node->extends) {
			$digged = $this->dig_members_in_extends_for($node);
			$node->aggregated_members = array_merge($digged, $node->aggregated_members);
		}

		if ($node->implements) {
			$digged = $this->dig_members_in_implements_for($node);
			$node->aggregated_members = array_merge($digged, $node->aggregated_members);
		}

		// the members in this class have the highest priority
		$node->aggregated_members = array_merge($node->aggregated_members, $node->symbols);

		$node->is_linked = true;
	}

	private function check_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$node->is_checked = true;

		// first check the members in this class, and the inferred types will be used later
		foreach ($node->members as $member) {
			$this->check_class_member_declaration($member);
		}
	}

	private function preprocess_bases_for_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$is_interface = $node instanceof InterfaceDeclaration;
		$class_identifiers = [];
		$interface_identifiers = [];
		foreach ($node->extends as $identifier) {
			$based_decl = $this->get_unchecked_classkindred_declaration($identifier);
			// if ($identifier->generic_types) {
			// 	$this->check_generic_types($identifier);
			// }

			if ($based_decl instanceof ClassDeclaration) {
				if ($is_interface) {
					throw $this->new_syntax_error("Cannot to inherits a class for interface '{$node->name}'", $node);
				}
				elseif ($class_identifiers) {
					throw $this->new_syntax_error("Only one super class could be inherits for class '{$node->name}'", $node);
				}
				else {
					$class_identifiers[] = $identifier;
				}
			}
			else {
				$interface_identifiers[] = $identifier;
			}
		}

		if ($is_interface) {
			if ($node->implements) {
				throw $this->new_syntax_error("Cannot implements in interface '{$node->name}'", $node);
			}
		}
		else {
			if ($node->implements && $interface_identifiers) {
				throw $this->new_syntax_error("Please appending interfaces by implements", $node);
			}

			$node->extends = $class_identifiers;
			$node->implements = $interface_identifiers;
		}
	}

	// private function check_generic_types(PlainIdentifier $identifier) {
	// 	foreach ($identifier->generic_types as $key => $type) {
	// 		$this->check_type($type);
	// 	}
	// }

	private function dig_trait_members_for(ClassKindredDeclaration $node)
	{
		$items = [];
		foreach ($node->usings as $using) {
			foreach ($using->items as $identifier) {
				$decl = $this->get_unchecked_classkindred_declaration($identifier);
				if (!$decl instanceof TraitDeclaration) {
					throw $this->new_syntax_error("Invalid using trait of '{$identifier->name}'", $identifier);
				}

				$node->unite_feature_flags($decl->feature_flags);
				$items = array_merge($items, $decl->trait_members);
				foreach ($decl->aggregated_members as $name => $member_symbol) {
					$items[$name] = $member_symbol;
				}
			}
		}

		return $items;
	}

	private function dig_members_in_extends_for(ClassKindredDeclaration $node)
	{
		$items = [];
		foreach ($node->extends as $identifier) {
			// add to the actual members of this class
			// inherited members belong to super and have the lowest priority
			$based_decl = $this->get_unchecked_classkindred_declaration($identifier);

			$node->unite_feature_flags($based_decl->feature_flags);
			$items = array_merge($items, $based_decl->aggregated_members);
		}

		// check if there are overridden members in this class that match the parent class members
		foreach ($items as $name => $super_member_symbol) {
			$current_member_symbol = $node->aggregated_members[$name] ?? null;
			if ($current_member_symbol) {
				// check super class member declared in current class
				$super_member = $super_member_symbol->declaration;
				$current_member = $current_member_symbol->declaration;
				$this->check_overrided_member($current_member, $super_member);
			}
		}

		return $items;
	}

	private function dig_members_in_implements_for(ClassKindredDeclaration $node)
	{
		$items = [];
		// The default implementation of members in the interface belongs to 'this' and has a higher priority.
		// The default implementation of subsequent interfaces will override the previous ones
		foreach ($node->implements as $identifier) {
			$based_decl = $this->get_unchecked_classkindred_declaration($identifier);
			if (!$based_decl instanceof InterfaceDeclaration) {
				throw $this->new_syntax_error("Cannot implements to a class '{$identifier->name}'", $identifier);
			}

			$node->unite_feature_flags($based_decl->feature_flags);

			foreach ($based_decl->aggregated_members as $name => $member_symbol) {
				$member_decl = $member_symbol->declaration;
				$exist_member_symbol = $items[$name] ?? null;
				if ($exist_member_symbol) {
					// check member declared in bases class/interfaces
					$exist_member = $exist_member_symbol->declaration;
					$this->check_overrided_member($exist_member, $member_decl, true);

					// replace to the default method implementation in interface
					if ($member_decl instanceof MethodDeclaration && $member_decl->body !== null) {
						$items[$name] = $member_symbol;
					}
				}
				else {
					$items[$name] = $member_symbol;
				}
			}
		}

		// If it is a class definition, finally check if there are any unimplemented interface members
		if ($node instanceof ClassDeclaration && $node->define_mode) {
			foreach ($items as $name => $interface_member_symbol) {
				$interface_member = $interface_member_symbol->declaration;
				$current_member_symbol = $node->aggregated_members[$name] ?? null;
				if ($current_member_symbol) {
					// check member declared in current class/interface
					$current_member = $current_member_symbol->declaration;
					$this->check_overrided_member($current_member, $interface_member, true);
				}
				elseif ($interface_member instanceof MethodDeclaration && $interface_member->body === null) {
					$interface = $interface_member->belong_block;
					throw $this->new_syntax_error("Method protocol '{$interface->name}.{$name}' required an implementation in class '{$node->name}'", $node);
				}
			}
		}

		return $items;
	}

	protected function check_overrided_member(IClassMemberDeclaration $node, IClassMemberDeclaration $super, bool $is_interface = false)
	{
		// do not need to check for construct
		if ($node->name === _CONSTRUCT) {
			return;
		}

		// the accessing modifer
		$super_modifier = $super->modifier ?? _PUBLIC;
		$current_modifier = $node->modifier ?? _PUBLIC;
		if ($super_modifier !== $current_modifier) {
			if (($super_modifier === _PROTECTED || $super_modifier === _INTERNAL) && $current_modifier === _PUBLIC) {
				// pass
			}
			else {
				throw $this->new_syntax_error("Modifier in '{$node->belong_block->name}.{$node->name}' must be same as '{$super->belong_block->name}.{$super->name}'", $node);
			}
		}

		$covariance_mode = false;
		if ($super instanceof MethodDeclaration) {
			if (!$node instanceof MethodDeclaration) {
				throw $this->new_syntax_error("Kind of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$super->belong_block->name}.{$super->name}'", $node);
			}

			$this->check_overrided_method_parameters($node, $super);
			$covariance_mode = true;
		}
		elseif ($super instanceof PropertyDeclaration) {
			if (!$node instanceof PropertyDeclaration) {
				throw $this->new_syntax_error("Kind of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$super->belong_block->name}.{$super->name}'", $node);
			}
		}
		elseif ($super instanceof ClassConstantDeclaration && $is_interface) {
			throw $this->new_syntax_error("Cannot override interface constant '{$super->belong_block->name}.{$super->name}' in '{$node->belong_block->name}'", $node);
		}

		$this->check_overrided_member_declared_type($node, $super, $covariance_mode);
	}

	private function check_overrided_member_declared_type(IClassMemberDeclaration $node, IClassMemberDeclaration $super, bool $covariance_mode)
	{
		// only check the declared
		$current_type = $node->declared_type;
		$super_type = $super->declared_type;

		if ($super_type === null) {
			if ($current_type === null) {
				return;
			}

			$current_type_name = $this->get_type_name($current_type);
			throw $this->new_syntax_error("There are declared type '{$current_type_name}' in '{$node->belong_block->name}', but not declared in '{$super->belong_block->name}'", $node);
		}

		if ($current_type === null) {
			$super_type_name = $this->get_type_name($super_type);
			throw $this->new_syntax_error("There are not declared type in '{$node->belong_block->name}', but declared '{$super_type_name}' in '{$super->belong_block->name}'", $node);
		}

		$compatible = $covariance_mode
			? $current_type->is_same_or_based_with($super_type)  // supports Covariance
			: $current_type->is_same_with($super_type);
		if (!$compatible) {
			$current_type_name = $this->get_type_name($current_type);
			$super_type_name = $this->get_type_name($super_type);
			throw $this->new_syntax_error("The declared type '{$current_type_name}' in '{$node->belong_block->name}' is incompatible with '$super_type_name' in '{$super->belong_block->name}'", $node);
		}
	}

	private function check_overrided_method_parameters(MethodDeclaration $node, MethodDeclaration $protocol)
	{
		if ($protocol->parameters === null && $protocol->parameters === null) {
			return;
		}

		// the parameters count
		if (count($protocol->parameters) > count($node->parameters)) {
			throw $this->new_syntax_error("Parameters of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$protocol->belong_block->name}.{$protocol->name}'", $node);
		}

		// the parameter types
		foreach ($protocol->parameters as $idx => $protocol_param) {
			$node_param = $node->parameters[$idx];
			$super_type = $protocol_param->get_hinted_type();
			$current_type = $node_param->get_hinted_type();
			if (!$this->is_overrided_method_param_compatible_types($super_type, $current_type) && !$this->is_weakly_checking) {
				$type_name = $this->get_type_name($current_type);
				throw $this->new_syntax_error("Parameter '{$node_param->name} {$type_name}' in '{$node->belong_block->name}.{$node->name}' is incompatible with '{$protocol->belong_block->name}.{$protocol->name}'", $node_param);
			}
		}
	}

	private function is_overrided_method_param_compatible_types(IType $super, IType $current)
	{
		return $super === $current
			|| $super->symbol === $current->symbol
			|| ($super === TypeFactory::$_int && $current === TypeFactory::$_uint)
			|| $current instanceof UnionType && $current->is_accept_type($super)  // supports Contravariance
		;
	}

	private function infer_if_block(IfBlock $node): ?IType
	{
		$result_type = $this->infer_base_if_block($node);

		// if ($node->has_exceptional()) {
		// 	$result_type = $this->reduce_types_with_except_block($node, $result_type);
		// }

		return $result_type;
	}

	private function infer_base_if_block(BaseIfBlock $node): ?IType
	{
		$this->infer_expression($node->condition);

		$assertion = $this->get_type_assertion($node->condition);
		if ($assertion) {
			// with type assertion
			$result_type = $this->infer_base_if_block_with_assertion($node, $assertion);
		}
		else {
			// without type assertion
			$result_type = $this->infer_block($node);
			if ($node->else) {
				$result_type = $this->reduce_types_with_else_block($node, $result_type);
			}
		}

		return $result_type;
	}

	private function get_type_assertion(BaseExpression $condition)
	{
		$assertion = null;
		if ($condition instanceof BinaryOperation) {
			$assertion = $condition->type_assertion;
		}
		elseif ($condition instanceof Identifiable) {
			$assertion = new IsOperation($condition, TypeFactory::$_none, true);
		}

		return $assertion;
	}

	private function infer_base_if_block_with_assertion(BaseIfBlock $node, IsOperation $type_assertion): ?IType
	{
		// cannot use type assertion when not an Identifiable
		if (!$type_assertion->left instanceof Identifiable) {
			// check block body
			$result_type = $this->infer_block($node);
			if ($node->else) {
				$result_type = $this->reduce_types_with_else_block($node, $result_type);
			}

			return $result_type;
		}

		$left_decl = $type_assertion->left->symbol->declaration;
		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = $left_decl->infered_type;
		$left_type = $left_original_type ?? TypeFactory::$_any;
		$asserting_type = $type_assertion->right;

		if ($type_assertion->not) {
			if ($left_type instanceof UnionType) {
				$asserted_then_type = $left_type->get_members_type_except($asserting_type);
			}
			elseif ($asserting_type instanceof NoneType) {
				$asserted_then_type = clone $left_type;
				$asserted_then_type->remove_nullable();
			}

			$asserted_else_type = $asserting_type;
		}
		else {
			if ($left_type instanceof UnionType) {
				$asserted_else_type = $left_type->get_members_type_except($asserting_type);
			}

			$asserted_then_type = $asserting_type;
		}

		// it would infer with the asserted then type
		if ($asserted_then_type) {
			$left_decl->set_type($asserted_then_type);
		}

		// check block body
		$result_type = $this->infer_block($node);

		// if assert none, and returned, means removed null
		if ($node->is_transfered and $asserted_then_type instanceof NoneType and $left_type->has_null) {
			$left_type->has_null = false;
		}

		if ($node->else) {
			// it would infer with the asserted else type
			$left_decl->set_type($asserted_else_type ?? $left_original_type);
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		// reset to original type
		$left_decl->set_type($left_original_type);

		return $result_type;
	}

	protected function reduce_types_with_else_block(IElseAble $node, ?IType $previous_type): ?IType
	{
		$infered = $this->infer_else_block($node->else);
		return $previous_type
			? $this->reduce_types([$previous_type, $infered])
			: $infered;
	}

	protected function infer_else_block(IElseBlock $node): ?IType
	{
		if ($node instanceof ElseIfBlock) {
			$result_type = $this->infer_base_if_block($node);
		}
		else {
			$result_type = $this->infer_block($node);
		}

		return $result_type;
	}

	private function reduce_types_with_except_block(IExceptAble $node, ?IType $previous_type)
	{
		$types = [];
		if ($previous_type) {
			$types[] = $previous_type;
		}

		foreach ($node->catchings as $sub_block) {
			$types[] = $this->infer_catch_block($sub_block);
		}

		if ($node->finally) {
			$types[] = $this->infer_block($node->finally);
		}

		$reduced = $this->reduce_types($types);
		return $reduced;
	}

	private function infer_catch_block(CatchBlock $node)
	{
		$var = $node->var;
		if ($var->declared_type === null) {
			$var->declared_type = TypeFactory::get_base_exception_type();
		}

		$this->check_variable_declaration($var);
		$infered = $this->infer_block($node);

		return $infered;
	}

	private function infer_switch_block(SwitchBlock $node): ?IType
	{
		$matching_type = $this->infer_expression($node->test);
		if (!$this->is_weakly_checking && !TypeHelper::is_case_testable_type($matching_type)) {
			$matching_type_name = self::get_type_name($matching_type);
			throw $this->new_syntax_error("The case compare expression should be String/Int/UInt, $matching_type_name given", $node->test);
		}

		$infereds = [];
		foreach ($node->branches as $branch) {
			foreach ($branch->rule_arguments as $argument) {
				if ($argument === null) {
					// the default branch
					continue;
				}

				$case_type = $this->infer_expression($argument);
				if (!TypeHelper::is_switch_compatible($matching_type, $case_type)) {
					$matching_type_name = self::get_type_name($matching_type);
					$case_type_name = self::get_type_name($case_type);
					throw $this->new_syntax_error("Incompatible matching types, matching type is $matching_type_name, case type is $case_type_name", $argument);
				}
			}

			$infereds[] = $this->infer_block($branch);
		}

		$result_type = $infereds ? $this->reduce_types($infereds) : null;

		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		// if ($node->has_exceptional()) {
		// 	$result_type = $this->reduce_types_with_except_block($node, $result_type);
		// }

		return $result_type;
	}

	private function infer_for_block(ForBlock $node): ?IType
	{
		$this->check_expression_list($node->args1);
		$this->check_expression_list($node->args2);
		$this->check_expression_list($node->args3);

		$result_type = $this->infer_block($node);
		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	private function check_expression_list(array $list)
	{
		foreach ($list as $expr) {
			$this->infer_expression($expr);
		}
	}

	private function infer_foreach_block(ForEachBlock $node): ?IType
	{
		$element_types = $this->expect_iter_element_types_for_expr($node->iterable);

		[$key_type, $val_type] = $element_types;
		$key = $node->key;
		$val = $node->val;
		if ($key instanceof PlainIdentifier) {
			$key->symbol->declaration->infered_type = $key_type;
		}
		if ($val instanceof PlainIdentifier) {
			$val->symbol->declaration->infered_type = $val_type;
		}

		$result_type = $this->infer_block($node);
		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_forin_block(ForInBlock $node): ?IType
	{
		$element_types = $this->expect_iter_element_types_for_expr($node->iterable);

		$key = $node->key;
		$val = $node->val;
		$val->is_checked = true;

		[$key_type, $val->infered_type] = $element_types;

		if ($key) {
			$key->infered_type = $key_type;
			$key->is_checked = true;
		}

		$result_type = $this->infer_block($node);
		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		// if ($node->has_exceptional()) {
		// 	$result_type = $this->reduce_types_with_except_block($node, $result_type);
		// }

		return $result_type;
	}

	private function expect_iter_element_types_for_expr(BaseExpression $expr)
	{
		$iter_infered = $this->infer_expression($expr);
		$element_types = $this->infer_iter_element_types($iter_infered);
		if ($element_types === null) {
			if ($this->is_weakly_checking) {
				$element_types = [TypeFactory::$_dict_key, TypeFactory::$_any];
			}
			else {
				$type_name = self::get_type_name($iter_infered);
				throw $this->new_syntax_error("Expected iterable type value, {$type_name} given", $expr);
			}
		}

		return $element_types;
	}

	private function infer_iter_element_types(IType $expr_type)
	{
		if ($expr_type instanceof IterableType) {
			// for Array or Dict
			$key_type = $expr_type instanceof ArrayType
				? TypeFactory::$_uint
				: TypeFactory::create_union_type([TypeFactory::$_uint, TypeFactory::$_string]);
			$val_type = $expr_type->generic_type ?? TypeFactory::$_any;
			$element_types = [$key_type, $val_type];
		}
		elseif ($expr_type instanceof PlainIdentifier and $based_iter_ident = TypeFactory::find_iterator_identifier($expr_type)) {
			// for Iterator
			$key_type = $based_iter_ident->generic_types['K'] ?? TypeFactory::$_any;
			$val_type = $based_iter_ident->generic_types['V'] ?? TypeFactory::$_any;
			$element_types = [$key_type, $val_type];
		}
		elseif ($expr_type instanceof UnionType) {
			$element_types = $this->infer_iter_element_types_for_union_type($expr_type);
		}
		else {
			$element_types = null;
		}

		return $element_types;
	}

	private function infer_iter_element_types_for_union_type(UnionType $type) {
		$key_types = [];
		$val_types = [];
		foreach ($type->get_members() as $member_type) {
			$element_types = $this->infer_iter_element_types($member_type);
			if ($element_types === null) {
				return null;
			}

			[$key_type, $val_type] = $element_types;
			$key_types[] = $key_type;
			$val_types[] = $val_type;
		}

		return [TypeFactory::create_union_type($key_types), TypeFactory::create_union_type($val_types)];
	}

	private function infer_forto_block(ForToBlock $node): ?IType
	{
		$start_type = $this->expect_infered_type($node->start, TypeFactory::$_int_types);
		$end_type = $this->expect_infered_type($node->end, TypeFactory::$_int_types);

		$key = $node->key;
		if ($key) {
			$key->infered_type = TypeFactory::$_uint;
			$key->is_checked = true;
		}

		$val = $node->val;
		$val->is_checked = true;

		// infer the val type
		$val->infered_type = ($start_type === TypeFactory::$_int || $end_type === TypeFactory::$_int)
			? TypeFactory::$_int
			: TypeFactory::$_uint;

		$result_type = $this->infer_block($node);
		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		// if ($node->has_exceptional()) {
		// 	$result_type = $this->reduce_types_with_except_block($node, $result_type);
		// }

		return $result_type;
	}

	private function infer_while_block(WhileBlock $node): ?IType
	{
		$this->infer_expression($node->condition);
		$result_type = $this->infer_block($node);

		// if ($node->has_exceptional()) {
		// 	$result_type = $this->reduce_types_with_except_block($node, $result_type);
		// }

		return $result_type;
	}

	private function infer_loop_block(LoopBlock $node): ?IType
	{
		$result_type = $this->infer_block($node);

		// if ($node->has_exceptional()) {
		// 	$result_type = $this->reduce_types_with_except_block($node, $result_type);
		// }

		return $result_type;
	}

	private function infer_try_block(TryBlock $node): ?IType
	{
		$result_type = $this->infer_block($node);

		if ($node->has_exceptional()) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_single_expression_block(IBlock $block): IType
	{
		// maybe block to check not a sub-block, so need a temp
		$temp_block = $this->block;
		$this->block = $block;

		$infered = $this->infer_expression($block->body);

		$this->block = $temp_block;

		return $infered;
	}

	private function infer_block(IBlock $block): ?IType
	{
		// maybe block to check not a sub-block, so need a temp
		$temp_block = $this->block;
		$this->block = $block;

		$infereds = [];
		foreach ($block->body as $statement) {
			if ($type = $this->infer_statement($statement)) {
				$infereds[] = $type;
			}
		}

		$this->block = $temp_block;

		return $infereds ? $this->reduce_types($infereds, $block) : null;
	}

	private function is_control_transfering(IStatement $node)
	{
		return $node instanceof ExitStatement
			|| $node instanceof ReturnStatement
			|| $node instanceof ThrowStatement
		;
	}

	private function infer_statement(IStatement $node): ?IType
	{
		$infered = null;
		switch ($node::KIND) {
			case NormalStatement::KIND:
				$node->expression and $this->infer_expression($node->expression);
				break;
			// case ArrayElementAssignment::KIND:
			// 	$this->check_array_element_assignment($node);
			// 	break;
			case EchoStatement::KIND:
				$this->check_echo_statement($node);
				break;
			case ThrowStatement::KIND:
				$this->check_throw_statement($node);
				break;
			case VarStatement::KIND:
				$this->check_var_statement($node);
				break;
			// case VariableDeclaration::KIND:
			// 	$this->check_variable_declaration($node);
			// 	break;
			case ReturnStatement::KIND:
				$infered = $this->infer_return_statement($node);
				break;
			case ExitStatement::KIND:
				$this->check_exit_statement($node);
			case BreakStatement::KIND:
			case ContinueStatement::KIND:
				break;
			case UnsetStatement::KIND:
				$this->check_unset_statement($node);
				break;
			case IfBlock::KIND:
				$infered = $this->infer_if_block($node);
				break;
			case ForBlock::KIND:
				$infered = $this->infer_for_block($node);
				break;
			case ForEachBlock::KIND:
				$infered = $this->infer_foreach_block($node);
				break;
			case ForInBlock::KIND:
				$infered = $this->infer_forin_block($node);
				break;
			case ForToBlock::KIND:
				$infered = $this->infer_forto_block($node);
				break;
			case WhileBlock::KIND:
				$infered = $this->infer_while_block($node);
				break;
			case LoopBlock::KIND:
				$infered = $this->infer_loop_block($node);
				break;
			case TryBlock::KIND:
				$infered = $this->infer_try_block($node);
				break;
			case SwitchBlock::KIND:
				$infered = $this->infer_switch_block($node);
				break;
			case UseStatement::KIND:
				break;
			case LineComment::KIND:
			case BlockComment::KIND:
			case DocComment::KIND:
				break;
			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unknow statement kind: '{$kind}'", $node);
		}

		return $infered;
	}

	private function expect_infered_type(BaseExpression $node, array $types)
	{
		$infered = $this->infer_expression($node);
		if (!in_array($infered, $types, true)) {
			$names = array_column($types, 'name');
			$names = join(' or ', $names);
			$infered_name = self::get_type_name($infered);
			throw $this->new_syntax_error("Expected type $names, {$infered_name} given", $node);
		}

		return $infered;
	}

	private function check_throw_statement(ThrowStatement $node)
	{
		$this->infer_expression($node->argument);
	}

	private function infer_return_statement(ReturnStatement $node)
	{
		$infered = $node->argument ? $this->infer_expression($node->argument) : null;
		return $infered;
	}

	private function check_exit_statement(ExitStatement $node)
	{
		$node->argument === null || $this->expect_infered_type($node->argument, TypeFactory::$_int_and_string_types);
	}

	private function check_unset_statement(UnsetStatement $node)
	{
		$argument = $node->argument;
		$this->infer_expression($argument);

		if (!$argument instanceof KeyAccessing) {
			throw $this->new_syntax_error("The unset target must be a KeyAccessing", $argument);
		}
	}

	private function check_var_statement(VarStatement $node)
	{
		foreach ($node->members as $member) {
			$this->check_variable_declaration($member);
		}
	}

	private function check_echo_statement(EchoStatement $node)
	{
		foreach ($node->arguments as $argument) {
			$this->infer_expression($argument);
		}
	}

	// private function check_array_element_assignment(ArrayElementAssignment $node)
	// {
	// 	$basing_type = $this->infer_expression($node->basing);

	// 	if (!$basing_type) {
	// 		throw $this->new_syntax_error("identifier '{$node->basing->name}' not defined", $node->basing);
	// 	}

	// 	if (!$basing_type instanceof ArrayType && $basing_type !== TypeFactory::$_any) {
	// 		throw $this->new_syntax_error("Cannot assign with element accessing for type '{$basing_type->name}' expression", $node->basing);
	// 	}

	// 	if ($node->key) {
	// 		$key_type = $this->infer_expression($node->key);
	// 		if ($key_type !== TypeFactory::$_uint) {
	// 			throw $this->new_syntax_error("Type for Array key expression should be int", $node);
	// 		}
	// 	}

	// 	// check the value type is valid
	// 	$infered = $this->infer_expression($node->value);
	// 	if ($basing_type !== TypeFactory::$_any && $basing_type->generic_type) {
	// 		$this->assert_type_compatible($basing_type->generic_type, $infered, $node->value);
	// 	}
	// }

	private function infer_interpolation(Interpolation $interpolation): IType
	{
		$infered = $this->infer_expression($interpolation->content);
		$interpolation->expressed_type = $infered;
		return $infered;
	}

	private function infer_expression(BaseExpression $node): IType
	{
		if ($node->expressed_type) {
			return $node->expressed_type;
		}

		switch ($node::KIND) {
			case PlainIdentifier::KIND:
				$infered = $this->infer_plain_identifier($node);
				break;
			// case BaseType::KIND:
			// 	$infered = $node;
			// 	break;
			case LiteralNone::KIND:
				$infered = TypeFactory::$_none;
				break;
			case LiteralDefaultMark::KIND:
				$infered = TypeFactory::$_default_marker;
				break;
			case PlainLiteralString::KIND:
				$infered = TeaHelper::is_pure_string($node->value)
					? TypeFactory::$_pures
					: TypeFactory::$_string;
				break;
			case EscapedLiteralString::KIND:
				$infered = TypeFactory::$_string;
				break;
			case LiteralInteger::KIND:
				$infered = TypeFactory::$_uint;
				break;
			case LiteralFloat::KIND:
				$infered = TypeFactory::$_float;
				break;
			case LiteralBoolean::KIND:
				$infered = TypeFactory::$_bool;
				break;

			//----
			case PlainInterpolatedString::KIND:
				$infered = $this->infer_plain_interpolated_string($node);
				break;
			case EscapedInterpolatedString::KIND:
				$infered = $this->infer_escaped_interpolated_string($node);
				break;
			case XTag::KIND:
				$infered = $this->infer_xtag($node);
				break;

			// -------
			case AccessingIdentifier::KIND:
				$infered = $this->infer_accessing_identifier($node);
				break;
			case KeyAccessing::KIND:
				$infered = $this->infer_key_accessing($node);
				break;
			case SquareAccessing::KIND:
				$infered = $this->infer_square_accessing($node);
				break;
			case VariableIdentifier::KIND:
				$infered = $this->infer_variable_identifier($node);
				break;
			case ConstantIdentifier::KIND:
				$infered = $this->infer_constant_identifier($node);
				break;
			case InstancingExpression::KIND:
				$infered = $this->infer_new_expression($node);
				break;
			case CallExpression::KIND:
				$infered = $this->infer_call_expression($node);
				break;
			case PipeCallExpression::KIND:
				$infered = $this->infer_pipecall_expression($node);
				break;

			case AssignmentOperation::KIND:
				$infered = $this->infer_assignment_operation($node);
				break;
			case BinaryOperation::KIND:
				$infered = $this->infer_binary_operation($node);
				break;
			case AsOperation::KIND:
				$infered = $this->infer_as_operation($node);
				break;
			case IsOperation::KIND:
				$infered = $this->infer_is_operation($node);
				break;
			case PrefixOperation::KIND:
				$infered = $this->infer_prefix_operation($node);
				break;
			case PostfixOperation::KIND:
				$infered = $this->infer_postfix_operation($node);
				break;

			case Parentheses::KIND:
				$infered = $this->infer_expression($node->expression);
				break;
			// case StringInterpolation::KIND:
			// 	$infered = $this->infer_expression($node->expression);
			// 	break;
			case NoneCoalescingOperation::KIND:
				$infered = $this->infer_none_coalescing_expression($node);
				break;
			case TernaryExpression::KIND:
				$infered = $this->infer_ternary_expression($node);
				break;
			case DictExpression::KIND:
				$infered = $this->infer_dict_expression($node);
				break;
			case ObjectExpression::KIND:
				$infered = $this->infer_object_expression($node);
				break;
			case ArrayExpression::KIND:
				$infered = $this->infer_array_expression($node);
				break;
			case AnonymousFunction::KIND:
				$infered = $this->infer_anonymous_function($node);
				break;
			// case NamespaceIdentifier::KIND:
			// 	$infered = $this->infer_namespace_identifier($node);
			//	break;
			case IncludeExpression::KIND:
				$infered = $this->infer_include_expression($node);
				break;
			case YieldExpression::KIND:
				$infered = $this->infer_yield_expression($node);
				break;
			case RegularExpression::KIND:
				$infered = $this->infer_regular_expression($node);
				break;
			// case ReferenceOperation::KIND:
			// 	$infered = $this->infer_expression($node->identifier);
			// 	break;
			// case RelayExpression::KIND:
			// 	$infered = $this->infer_relay_expression($node);
			// 	break;
			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unknow expression kind: '{$kind}'", $node);
		}

		$node->expressed_type = $infered;
		return $infered;
	}

	private function infer_square_accessing(SquareAccessing $node): IType
	{
		$basing_type = $this->infer_expression($node->basing);

		$infered = null;
		if ($basing_type instanceof ArrayType) {
			$infered = $basing_type->generic_type ?? TypeFactory::$_any;
		}
		elseif ($basing_type instanceof AnyType) {
			$infered = TypeFactory::$_any;
		}
		elseif ($basing_type instanceof UnionType) {
			if ($basing_type->is_all_array_types()) {
				$member_value_types = [];
				foreach ($basing_type->get_members() as $member) {
					$member_value_types[] = $member->generic_type;
				}

				$infered = $this->reduce_types($member_value_types);
			}
			elseif ($this->is_weakly_checking) {
				$infered = TypeFactory::$_any;
			}
		}

		if ($infered === null) {
			$type_name = $this->get_type_name($basing_type);
			throw $this->new_syntax_error("Cannot use square accessing for type {$type_name}", $node);
		}

		return $infered;
	}

	private function infer_key_accessing(KeyAccessing $node): IType
	{
		$key_expr = $node->key;
		$basing_type = $this->infer_expression($node->basing);
		$key_type = $key_expr ? $this->infer_expression($key_expr) : null;

		if ($basing_type instanceof ArrayType) {
			if ($key_expr and $key_type !== TypeFactory::$_uint and !$this->is_weakly_checking) {
				$type_name = self::get_type_name($key_type);
				throw $this->new_syntax_error("Index type for Array accessing should be UInt, {$type_name} given", $key_expr);
			}

			$infered = $basing_type->generic_type ?? TypeFactory::$_any;
		}
		elseif ($basing_type instanceof DictType) {
			if ($key_expr === null) {
				throw $this->new_syntax_error("Invalid accessing for Dict", $node);
			}

			$this->assert_dict_key_type($key_type, $key_expr);
			$infered = $basing_type->generic_type ?? TypeFactory::$_any;
		}
		elseif ($this->is_array_access_type($basing_type)) {
			// if non key, that's Array access, else just allow Dict as the actual type
			$key_expr and $this->assert_dict_key_type($key_type, $key_expr);
			$infered = TypeFactory::$_any;
		}
		elseif ($basing_type instanceof StringType) {
			if ($key_type !== TypeFactory::$_uint && $key_type !== TypeFactory::$_int) {
				throw $this->new_syntax_error("Index type for String should be Int/UInt, '{$key_type->name}' given", $node);
			}

			$infered = TypeFactory::$_string;
		}
		elseif ($basing_type instanceof UnionType) {
			if ($basing_type->is_all_array_types()) {
				if ($key_type !== TypeFactory::$_uint) {
					$type_name = self::get_type_name($key_type);
					throw $this->new_syntax_error("Index type for Array accessing should be UInt, {$type_name} given", $key_expr);
				}
			}
			elseif ($basing_type->is_all_dict_types()) {
				$this->assert_dict_key_type($key_type, $key_expr);
			}
			elseif ($this->is_weakly_checking && $basing_type->has_array_or_dict_type()) {
				// pass
			}
			else {
				$type_name = $this->get_type_name($basing_type);
				throw $this->new_syntax_error("Cannot use key accessing for type {$type_name}", $node);
			}

			$member_value_types = [];
			foreach ($basing_type->get_members() as $member) {
				$member_value_types[] = $member->generic_type ?? TypeFactory::$_any;
			}

			$infered = $this->reduce_types($member_value_types);
		}
		else {
			$type_name = $this->get_type_name($basing_type);
			throw $this->new_syntax_error("Cannot use key accessing for type {$type_name}", $node);
		}

		return $infered;
	}

	private function is_array_access_type(IType $type)
	{
		return $type instanceof AnyType
			|| ($type instanceof Identifiable
				&& $type->symbol->declaration->has_feature(ClassFeature::ARRAY_ACCESS));
	}

	private function assert_dict_key_type(IType $key_type, BaseExpression $key_expr)
	{
		if (!TypeHelper::is_dict_key_type($key_type) && !$this->is_weakly_checking) {
			throw $this->new_syntax_error("Key type for Dict accessing should be String/Int, '{$key_type->name}' given", $key_expr);
		}
	}

	private function infer_assignment_operation(AssignmentOperation $node)
	{
		$left = $node->left;
		$right = $node->right;
		$infered = $this->infer_expression($right);

		if ($infered === TypeFactory::$_void) {
			throw $this->new_syntax_error("The returns type is Void, cannot use as value", $right);
		}

		if ($left instanceof AccessingIdentifier) {
			$left_type = $this->infer_accessing_identifier($left);
		}
		elseif ($left instanceof KeyAccessing) {
			$left_type = $this->infer_key_accessing($left); // it should be not null
		}
		elseif ($left instanceof SquareAccessing) {
			$left_type = $this->infer_square_accessing($left); // it should be not null
		}
		elseif ($left instanceof PlainIdentifier) {
			$left_type = $left->symbol->declaration->declared_type;
		}
		elseif ($left instanceof Destructuring) {
			$left_type = $this->infer_destructuring($left);
		}
		elseif ($left instanceof BinaryOperation && $left->operator === OperatorFactory::$member_accessing) {
			$left_type = TypeFactory::$_any;
		}
		else {
			throw $this->new_syntax_error("Required assignable expression", $left);
		}

		if (!ASTHelper::is_assignable_expr($left)) {
			if ($left instanceof KeyAccessing) {
				throw $this->new_syntax_error("Cannot change a immutable item", $left->left);
			}
			elseif ($left instanceof SquareAccessing) {
				throw $this->new_syntax_error("Cannot change a immutable item", $left);
			}
			else {
				throw $this->new_syntax_error("Cannot assign to a final(un-reassignable) item", $left);
			}
		}

		if ($left_type) {
			$this->assert_type_compatible($left_type, $infered, $right);
		}
		else {
			// for the undeclared var
			// set type Any for assigned none
			$left_decl = $left->symbol->declaration;
			if ($left_decl->infered_type === null) {
				$left_decl->infered_type = $infered instanceof NoneType
					? TypeFactory::$_any
					: $infered;
			}
		}

		return $infered;
	}

	private function infer_destructuring(Destructuring $expr)
	{
		foreach ($expr->items as $item) {
			if ($item instanceof DictMember) {
				$this->infer_dict_member($item);
			}
			else {
				$this->infer_expression($item);
			}
		}

		return TypeFactory::$_array;
	}

	private function infer_binary_operation(BinaryOperation $node): IType
	{
		$operator = $node->operator;

		$left_expr = $node->left;
		$right_expr = $node->right;
		$left_type = $this->infer_expression($left_expr);
		$right_type = $this->infer_expression($right_expr);

		if (OperatorFactory::is_number_operator($operator)) {
			$this->assert_math_operable($left_type, $left_expr);
			$this->assert_math_operable($right_type, $right_expr);

			if ($left_type === TypeFactory::$_float || $right_type === TypeFactory::$_float) {
				if ($operator->is(OPID::REMAINDER)) {
					throw $this->new_syntax_error("Remainder operation cannot use in 'Float' type expression", $node);
				}

				$infered = TypeFactory::$_float;
			}
			elseif ($operator->is(OPID::DIVISION)) {
				$infered = TypeFactory::$_float;
			}
			elseif ($operator->is(OPID::NEGATION)) {
				$infered = TypeFactory::$_int;
			}
			elseif ($left_type === TypeFactory::$_int || $right_type === TypeFactory::$_int) {
				$infered = TypeFactory::$_int;
			}
			else {
				$infered = TypeFactory::$_uint;
			}
		}
		elseif (OperatorFactory::is_bool_operator($operator)) {
			$infered = TypeFactory::$_bool;

			// assign type assertion for and-operation
			// uses for assert type in if-block/conditional-expression
			if ($operator->is(OPID::BOOL_AND)) {
				if ($left_expr instanceof BinaryOperation and $left_expr->type_assertion) {
					$node->type_assertion = $left_expr->type_assertion;
				}
				elseif ($right_expr instanceof BinaryOperation and $right_expr->type_assertion) {
					$node->type_assertion = $right_expr->type_assertion;
				}
			}
		}
		elseif ($operator->is(OPID::CONCAT)) {
			// String or Array
			if ($left_type instanceof ArrayType) {
				$this->assert_type_compatible($left_type, $right_type, $right_expr, 'concat');
				$node->operator = OperatorFactory::$array_concat; // replace to array concat
				$infered = $left_type;
			}
			elseif (!TypeHelper::is_stringable_type($left_type) and !$this->is_weakly_checking) {
				$type_name = $this->get_type_name($left_type);
				throw $this->new_syntax_error("The concat operation cannot use for '$type_name' type targets", $left_expr);
			}
			else {
				// string
				$is_pure = $left_type instanceof IPureType && $right_type instanceof IPureType;
				$infered = $is_pure ? TypeFactory::$_pures : TypeFactory::$_string;
			}
		}
		elseif ($operator->is(OPID::REPEAT)) {
			if (!TypeHelper::is_stringable_type($left_type)) {
				$type_name = $this->get_type_name($left_type);
				throw $this->new_syntax_error("Expected Stringable, {$type_name} given", $left_expr);
			}
			elseif (!$right_type instanceof UIntType) {
				$type_name = $this->get_type_name($right_type);
				throw $this->new_syntax_error("Expected UInt, {$type_name} given", $right_expr);
			}

			// string
			$is_pure = $left_type instanceof IPureType;
			$infered = $is_pure ? TypeFactory::$_pures : TypeFactory::$_string;
		}
		// elseif ($operator->is(OPID::MERGE)) {
		// 	// Array or Dict
		// 	if (!$left_type instanceof DictType) {
		// 		throw $this->new_syntax_error("'merge' operation just support Dict type targets", $node);
		// 	}

		// 	$this->assert_type_compatible($left_type, $right_type, $right_expr, 'merge');
		// 	$infered = $left_type;
		// }
		elseif ($operator->is(OPID::NONE_COALESCING)) {
			$infered = $this->reduce_types([$left_type, $right_type]);
		}
		elseif (OperatorFactory::is_bitwise_operator($operator)) {
			$infered = $this->reduce_types([$left_type, $right_type]);
		}
		elseif ($operator->is(OPID::MEMBER_ACCESSING)) {
			$infered = TypeFactory::$_any;
		}
		else {
			$sign = $node->operator->get_debug_sign();
			throw $this->new_syntax_error("Unknow binary operator: {$sign}", $node);
		}

		return $infered;
	}

	private function infer_as_operation(AsOperation $node): IType
	{
		$this->infer_expression($node->left);
		$this->check_type($node->right, null);

		$cast_type = $node->right;

		if (!$cast_type instanceof IType) {
			throw $this->new_syntax_error("Invalid 'as' expression '{$node->right->name}'", $node);
		}

		// if ($cast_type instanceof CallableType) {
		// 	throw $this->new_syntax_error("Cannot mark as Callable", $node);
		// }

		return $cast_type;
	}

	private function infer_is_operation(IsOperation $node): IType
	{
		$this->infer_expression($node->left);
		$this->check_type($node->right);

		$assert_type = $node->right;
		if (!$assert_type instanceof IType) {
			$kind = $node->right::KIND;
			throw $this->new_syntax_error("Invalid 'is' expression '{$kind}'", $node);
		}

		if ($node->left instanceof Identifiable) {
			// it self is a type assertion
			$node->type_assertion = $node;
		}

		return TypeFactory::$_bool;
	}

	private function infer_prefix_operation(PrefixOperation $node): IType
	{
		$expr_type = $this->infer_expression($node->expression);
		$operator = $node->operator;

		if ($operator->is(OPID::BOOL_NOT)) {
			$this->assert_bool_operable($expr_type, $node->expression);
			$infered = TypeFactory::$_bool;
		}
		elseif ($operator->is(OPID::NEGATION)) {
			$this->assert_math_operable($expr_type, $node->expression);

			// if is UInt or contais UInt, it must be became to Int after negation
			if ($expr_type === TypeFactory::$_uint) {
				$infered = TypeFactory::$_int;
			}
			elseif ($expr_type instanceof UnionType and $expr_type->is_contains_single_type(TypeFactory::$_uint)) {
				$infered = $expr_type->merge_with_single_type(TypeFactory::$_int);
			}
			else {
				$infered = $expr_type;
			}
		}
		elseif ($operator->is(OPID::REFERENCE) || $operator->is(OPID::PRE_INCREMENT)) {
			$infered = $expr_type;
		}
		elseif ($operator->is(OPID::PRE_DECREMENT)) {
			$infered = $expr_type instanceof UIntType ? TypeFactory::$_int : $expr_type;
		}
		elseif ($operator->is(OPID::BITWISE_NOT)) {
			$this->assert_bitwise_operable($expr_type, $node->expression);
			$infered = $expr_type === TypeFactory::$_uint || $expr_type === TypeFactory::$_int || $expr_type === TypeFactory::$_float
				? TypeFactory::$_int
				: $expr_type;
		}
		elseif ($operator->is(OPID::CLONE) || $operator->is(OPID::ERROR_CONTROL)) {
			$infered = $expr_type;
		}
		elseif ($operator->is(OPID::SPREAD)) {
			$infered = TypeFactory::$_array;
		}
		else {
			$sign = $operator->get_debug_sign();
			throw $this->new_syntax_error("Unknow prefix operator: {$sign}", $node);
		}

		return $infered;
	}

	private function infer_postfix_operation(PostfixOperation $node): IType
	{
		$expr_type = $this->infer_expression($node->expression);
		return $expr_type;
	}

	private function assert_bitwise_operable(IType $type, BaseExpression $node)
	{
		if (!$type instanceof UIntType && !$type instanceof IntType && !$type instanceof FloatType && !$type instanceof StringType) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Bitwise operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function assert_math_operable(IType $type, BaseExpression $node)
	{
		if (!TypeHelper::is_number_type($type, $node) && !$this->is_weakly_checking) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Math operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function assert_bool_operable(IType $type, BaseExpression $node)
	{
		if (!$this->is_weakly_checking
			&& !$type instanceof BoolType
			&& !$type instanceof UIntType
			&& !$type instanceof IntType) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Bool operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function infer_none_coalescing_expression(NoneCoalescingOperation $node): IType
	{
		$left_infered = $this->infer_expression($node->left);
		$right_infered = $this->infer_expression($node->right);
		$reduced_type = $this->reduce_types([$left_infered, $right_infered]);

		// none has been coalesced
		if (!$right_infered instanceof NoneType) {
			// Avoid affecting previously defined
			if (!$reduced_type instanceof UnionType) {
				$reduced_type = clone $reduced_type;
			}

			$reduced_type->remove_nullable();
		}

		return $reduced_type;
	}

	private function infer_ternary_expression(TernaryExpression $node): IType
	{
		$condition_type = $this->infer_expression($node->condition);

		// infer with type assert
		if ($node->condition instanceof BinaryOperation and $node->condition->type_assertion) {
			// with type assertion
			$infereds = $this->infer_ternary_expression_with_asserttion($node, $condition_type);
		}
		else {
			// without type assertion
			if ($node->then === null) {
				$then_type = $condition_type;
			}
			else {
				$then_type = $this->infer_expression($node->then);
			}

			$else_type = $this->infer_expression($node->else);

			$infereds = [$then_type, $else_type];
		}

		return $this->reduce_types($infereds);
	}

	private function infer_ternary_expression_with_asserttion(TernaryExpression $node, IType $condition_type): array
	{
		$type_assertion = $node->condition->type_assertion;

		$left_decl = $type_assertion->left->symbol->declaration;
		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = $left_decl->get_type();
		$asserting_type = $type_assertion->right;

		if ($type_assertion->not) {
			if ($left_original_type instanceof UnionType) {
				$asserted_then_type = $left_original_type->get_members_type_except($asserting_type);
			}

			$asserted_else_type = $asserting_type;
		}
		else {
			if ($asserting_type instanceof NoneType) {
				$asserted_else_type = clone $left_original_type;
				$asserted_else_type->remove_nullable();
			}
			elseif ($left_original_type instanceof UnionType) {
				$asserted_else_type = $left_original_type->get_members_type_except($asserting_type);
			}

			$asserted_then_type = $asserting_type;
		}

		if ($node->then === null) {
			$then_type = $condition_type;
		}
		else {
			// it would infer with the asserted then type
			$asserted_then_type and $left_decl->set_type($asserted_then_type);
			$then_type = $this->infer_expression($node->then);
		}

		// it would infer with the asserted else type
		$left_decl->set_type($asserted_else_type ?? $left_original_type);
		$else_type = $this->infer_expression($node->else);

		// reset to original type
		$left_decl->set_type($left_original_type);

		return [$then_type, $else_type];
	}

	private function infer_array_expression(ArrayExpression $node): IType
	{
		if (!$node->items) {
			return TypeFactory::$_array;
		}

		$infered_item_types = [];
		foreach ($node->items as $item) {
			$infered_item_types[] = $this->infer_expression($item);
		}

		$item_type = $this->reduce_types($infered_item_types);

		return TypeFactory::create_array_type($item_type);
	}

	private function infer_dict_expression(DictExpression $node): IType
	{
		if (!$node->items) {
			return TypeFactory::$_dict;
		}

		$infered_value_types = [];
		foreach ($node->items as $item) {
			if ($item instanceof DictMember) {
				$infered = $this->infer_dict_member($item);
			}
			else {
				$infered = $this->infer_expression($item);
			}

			$infered_value_types[] = $infered;
		}

		$generic_type = $this->reduce_types($infered_value_types);

		return TypeFactory::create_dict_type($generic_type);
	}

	private function infer_dict_member(DictMember $item)
	{
		$key_type = $this->infer_expression($item->key);
		if (!TypeHelper::is_dict_key_type($key_type) && !$this->is_weakly_checking) {
			$type_name = $this->get_type_name($key_type);
			throw $this->new_syntax_error("Key type for Dict should be String/Int, {$type_name} given", $item->key);
		}

		return $this->infer_expression($item->value);
	}

	private function infer_object_expression(ObjectExpression $node): IType
	{
		$this->check_classkindred_declaration($node->symbol->declaration);

		$infered = clone TypeFactory::$_object;
		$infered->symbol = $node->symbol;

		return $infered;
	}

	private function infer_callback_argument(CallbackArgument $node): IType
	{
		$value_expr = $node->value;
		if ($value_expr instanceof AnonymousFunction) {
			$this->infer_anonymous_function($value_expr);
			$decl = $value_expr;
		}
		else {
			$this->infer_expression($value_expr);
			$decl = $value_expr->symbol->declaration;
		}

		$infered = $decl->get_type();
		return $infered;
	}

	private function check_type(IType $type)
	{
		if ($type instanceof BaseType) {
			if ($type instanceof SingleGenericType) {
				// check the value type
				$type->generic_type !== null && $this->check_type($type->generic_type);
			}
			elseif ($type instanceof UnionType) {
				foreach ($type->get_members() as $member) {
					$this->check_type($member);
				}
			}
			elseif ($type instanceof CallableType) {
				$this->check_callable_type($type);
			}

			if (!$type->symbol) {
				$type->symbol = $this->find_type_symbol_and_check_declaration($type);
			}

			// no any other need to check
		}
		elseif ($type instanceof PlainIdentifier) {
			// $infered = $this->infer_plain_identifier($type);
			$decl = $this->get_actual_declaration_for_identifier($type);
			if (!$decl instanceof ClassKindredDeclaration) {
				$name = $this->get_declaration_name($decl);
				throw $this->new_syntax_error("Cannot use '$name' as a Type", $type);
			}
		}
		else {
			$kind = $type::KIND;
			throw $this->new_syntax_error("Unknow type kind '$kind'", $type);
		}
	}

	private function check_callable_type(CallableType $node)
	{
		$node->is_checked = true;

		$hinted = $this->get_and_check_hinted_type($node);
		$node->infered_type = $hinted ?? TypeFactory::$_void;

		$node->parameters and $this->check_parameters_for_callable_declaration($node);
	}

	private function infer_constant_identifier(ConstantIdentifier $node): IType
	{
		$decl = $this->get_actual_declaration_for_identifier($node);
		return $decl->get_type();
	}

	private function infer_yield_expression(YieldExpression $node): IType
	{
		$this->infer_expression($node->argument);
		return TypeFactory::$_any;
	}

	private function infer_include_expression(IncludeExpression $node): ?IType
	{
		$infered = $this->infer_expression($node->target);
		if (!$infered instanceof StringType) {
			throw $this->new_syntax_error("Expected String type expression", $node->target);
		}

		return TypeFactory::$_any;
	}

	// private function infer_include_target(IncludeExpression $node): ?IType
	// {
	// 	$including = $this->program;
	// 	$program = $this->require_program_declaration($node->target, $node);

	// 	$target_main = $program->initializer;
	// 	if ($target_main) {
	// 		// check all expect variables is decalared in current including place
	// 		foreach ($target_main->parameters as $parameter) {
	// 			$param_name = $parameter->name;
	// 			$symbol = $node->symbols[$param_name] ?? $this->find_plain_symbol_and_check_declaration($parameter);
	// 			if ($symbol === null) {
	// 				$checker = self::get_checker($including);
	// 				throw $checker->new_syntax_error("Expected var '{$param_name}' to #include('{$node->target}')", $node);
	// 			}
	// 		}

	// 		$infered = $target_main->declared_type;
	// 	}
	// 	else {
	// 		$infered = null;
	// 	}

	// 	$this->program = $including;

	// 	return $infered;
	// }

	// protected function require_program_declaration(string $name, Node $ref_node)
	// {
	// 	$program = $this->unit->programs[$name] ?? null;
	// 	if (!$program) {
	// 		throw $this->new_syntax_error("'{$ref_node->target}' not found", $ref_node);
	// 	}

	// 	// $program->unit->get_checker()->check_program($program);
	// 	self::get_checker($program)->check_program($program);

	// 	return $program;
	// }

	private function infer_new_expression(InstancingExpression $node): IType
	{
		$callee = $node->callee;

		// the endmost declaration, some times maybe not the direct
		if ($callee instanceof PlainIdentifier) {
			$decl = $this->get_checked_classkindred_declaration($callee);
		}
		else {
			$decl = $this->get_class_declaration_for_expr($callee);
		}

		// dump($expr);
		// throw $this->new_syntax_error("Debug", $expr);

		$infered = $this->infer_instancing_expr($callee, $decl);

		$this->check_call_arguments($node, $decl);

		return $infered;
	}

	private function infer_instancing_expr(BaseExpression $callee, IDeclaration $decl)
	{
		if (!$decl instanceof ClassDeclaration) {
			throw $this->new_syntax_error("Cannot instantiate '{$decl->name}'", $callee);
		}

		return $decl->typing_identifier;

		// if ($callee instanceof PlainIdentifier) {
		// 	// the direct declaration maybe not of the endmost
		// 	$direct_decl = $callee->symbol->declaration;
		// 	$direct_type = $direct_decl->get_type();
		// 	if ($direct_type instanceof MetaType) {
		// 		$infered = $direct_type->generic_type;
		// 	}
		// 	else {
		// 		$infered = $callee;
		// 	}
		// }
		// else {
		// 	$infered = $decl->typing_identifier;
		// }

		// return $infered;
	}

	private function infer_call_expression(CallExpression $node): IType
	{
		return $this->infer_basecall_expression($node);
	}

	private function infer_pipecall_expression(PipeCallExpression $node): IType
	{
		return $this->infer_basecall_expression($node);
	}

	private function infer_basecall_expression(BaseCallExpression $node): IType
	{
		$callee = $node->callee;

		// the endmost declaration, some times maybe not the direct
		$callable_decl = $this->require_callee_declaration($callee);

		// if ($callable_decl === null) {
		// 	// the Any-Callable type do not need to match parameters
		// 	foreach ($node->arguments as $argument) {
		// 		$this->infer_expression($argument);
		// 	}

		// 	$infered = TypeFactory::$_any;
		// }
		// else
		if ($callable_decl instanceof ClassKindredDeclaration) {
			$node->is_instancing = true;
			$infered = $this->infer_instancing_expr($callee, $callable_decl);
			// instancing arguments
			$this->check_call_arguments($node, $callable_decl);
		}
		elseif ($callable_decl === TypeFactory::$_callable or $callable_decl->is_virtual) {
			// the Any-Callable type do not need to match parameters
			foreach ($node->arguments as $argument) {
				$this->infer_expression($argument);
			}

			$infered = TypeFactory::$_any;
		}
		elseif ($callable_decl instanceof ICallableDeclaration) {
			// function return type
			$infered = $callable_decl->get_type();
			// function calling arguments
			$this->check_call_arguments($node, $callable_decl);
		}
		else {
			throw $this->new_syntax_error("Callee not a valid callable declaration", $callee);
		}

		if ($infered === null) {
			if ($this->is_weakly_checking) {
				$infered = TypeFactory::$_any;
			}
			else {
				throw $this->new_syntax_error("Unable to infer return type, there may have been a recursive call", $node);
			}
		}

		// for render
		$node->infered_callee_declaration = $callable_decl;

		return $infered;
	}

	private function get_last_variadic_param(array $parameters)
	{
		$len = count($parameters);
		if ($len === 0) {
			return null;
		}

		$param = $parameters[$len - 1];
		return $param->is_variadic ? $param : null;
	}

	private function check_call_arguments(BaseCallExpression $node, ICallableDeclaration $callee_decl)
	{
		$arguments = $node->arguments;

		// if is a class, use it's construct declaration
		if ($callee_decl instanceof ClassDeclaration) {
			$constructor_decl = $this->get_construct_declaration_for_class($callee_decl, $node);
			if ($constructor_decl === null) {
				if ($arguments) {
					if (!$this->is_weakly_checking) {
						// dump($callee_decl->symbols);
						throw $this->new_syntax_error("Cannot use arguments to create a non-construct class instance", $node);
					}

					foreach ($arguments as $argument) {
						$this->infer_expression($argument);
					}
				}

				return;
			}

			$callee_decl = $constructor_decl;
		}

		$parameters = $callee_decl->parameters;

		// the -> style callbacks for normal call
		if (isset($node->callbacks)) {
			$this->merge_callbacks_to_arguments($arguments, $node->callbacks, $parameters);
		}

		$has_named_arguments = false;
		$last_variadic_param = $this->get_last_variadic_param($parameters);

		$normalizeds = [];
		foreach ($arguments as $key => $argument) {
			if (is_numeric($key)) {
				$parameter = $parameters[$key] ?? $last_variadic_param;
				if (!$parameter) {
					$declar_name = $this->get_declaration_name($callee_decl);
					throw $this->new_syntax_error("Argument $key does not matched the parameter defined in '{$declar_name}'", $argument);
				}

				$idx = $key;
			}
			else {
				$has_named_arguments = true;
				list($idx, $parameter) = $this->require_parameter_by_name($key, $parameters, $node->callee);
			}

			// check type is match
			$param_type = $parameter->get_type();
			if ($param_type === null) {
				throw $this->new_syntax_error('Unexpected parameter type', $argument);
			}

			$infered = $this->infer_expression($argument);
			if (!$param_type->is_accept_type($infered) && !$this->is_weakly_checking) {
				$callee_name = self::get_declaration_name($callee_decl);
				$expected_name = self::get_type_name($param_type);
				$infered_name = self::get_type_name($infered);

				if (!is_int($key)) {
					$key = "'$key'";
				}

				throw $this->new_syntax_error("Type of argument $key does not matched the parameter, expected {$expected_name}, {$infered_name} given", $argument);
			}

			if ($parameter->is_inout) {
				if (!ASTHelper::is_assignable_expr($argument)) {
					throw $this->new_syntax_error("Argument $key is final(un-reassignable), cannot use for inout parameter", $argument);
				}
			}

			// if ($parameter->is_mutable) {
			// 	if (!ASTHelper::is_mutable_expr($argument)) {
			// 		throw $this->new_syntax_error("Argument $key is immutable, cannot use for value mutable parameter", $argument);
			// 	}
			// }

			$normalizeds[$idx] = $argument;
		}

		// check is has any required parameter
		foreach ($parameters as $idx => $param) {
			if ($param->value === null && !$param->is_variadic && !isset($normalizeds[$idx])) {
				$callee_name = self::get_declaration_name($callee_decl);
				$param_name = $callee_decl->parameters[$idx]->name;
				throw $this->new_syntax_error("Required argument '$param_name' to call '{$callee_name}'", $node);
			}
		}

		// fill the default value when needed
		if ($has_named_arguments) {
			$last_idx = array_key_last($normalizeds);

			$i = 0;
			foreach ($parameters as $param) {
				if ($i <= $last_idx && !isset($normalizeds[$i])) {
					$normalizeds[$i] = $param->value;
				}

				$i++;
			}

			ksort($normalizeds); // let to the normal order
			$node->normalized_arguments = $normalizeds;
		}
	}

	private function merge_callbacks_to_arguments(array &$arguments, array $callbacks, array $parameters)
	{
		if (count($callbacks) === 1 && $callbacks[0]->name === null) {
			$first_callback_parameter_on_tail = null;
			for ($i = count($parameters) - 1; $i >=0; $i--) {
				$parameter = $parameters[$i];
				$param_type = $parameter->get_type();
				if ($param_type instanceof CallableType) {
					$first_callback_parameter_on_tail = $parameter;
				}
				else {
					break;
				}
			}

			if (!$first_callback_parameter_on_tail) {
				throw $this->new_syntax_error("Unknow which parameter for callback", $callbacks[0]);
			}

			$callbacks[0]->name = $first_callback_parameter_on_tail->name;
		}

		foreach ($callbacks as $cb) {
			$arguments[$cb->name] = $cb->value;
		}
	}

	private function get_construct_declaration_for_class(ClassDeclaration $class, BaseCallExpression $call)
	{
		$symbol = $this->find_member_symbol_in_class_declaration($class, _CONSTRUCT);
		if (!$symbol) {
			// if ($call->arguments) {
			// 	throw $this->new_syntax_error("'construct' in '{$class->name}' not found", $call);
			// }

			return null; // no any to check
		}

		return $symbol->declaration;
	}

	private function assert_type_compatible(IType $left, IType $right, Node $value_node, string $kind = 'assign')
	{
		if (!$this->is_type_compatible($left, $right, $value_node) && !$this->is_weakly_checking) {
			if ($left === TypeFactory::$_none) {
				throw $this->new_syntax_error("It's required a type hint", $value_node);
			}

			$left_type_name = self::get_type_name($left);
			$right_type_name = self::get_type_name($right);

			// dump($value_node);
			throw $this->new_syntax_error("It's not compatible for type {$left_type_name}, {$kind} with {$right_type_name}", $value_node);
		}
	}

	private function is_type_compatible(IType $left, IType $right, Node $value_node)
	{
		if ($left->is_accept_type($right)) {
			return true;
		}

		// for [], [:]
		if ($value_node instanceof IArrayLikeExpression && !$value_node->items) {
			return true;
		}

		return false;
	}

	// @return [index, ParameterDeclaration]
	private function require_parameter_by_name(string $name, array $parameters, BaseExpression $callee_node)
	{
		foreach ($parameters as $idx => $parameter) {
			if ($parameter->name === $name) {
				return [$idx, $parameter];
			}
		}

		throw $this->new_syntax_error("Argument '$name' not found in declaration", $callee_node);
	}

	// return [index, CallbackProtocol]
	private function require_callback_protocol_by_name(string $name, array $callbacks, BaseExpression $callee_node)
	{
		foreach ($callbacks as $idx => $callback) {
			if ($callback->name === $name) {
				return [$idx, $callback];
			}
		}

		throw $this->new_syntax_error("Callback argument '$name' not found in declaration", $callee_node);
	}

	protected function infer_plain_identifier(PlainIdentifier $node): IType
	{
		$decl = $this->get_actual_declaration_for_identifier($node);

		if ($decl instanceof VariableDeclaration
			|| $decl instanceof ParameterDeclaration
			|| $decl instanceof ConstantDeclaration
			|| $decl instanceof ClassKindredDeclaration) {
			$type = $decl->get_type();
			if (!$type) {
				throw $this->new_syntax_error("Declaration of '{$node->name}' not found", $node);
			}
		}
		elseif ($decl instanceof CallableType) {
			$type = $decl;
		}
		elseif ($decl instanceof ICallableDeclaration) {
			$return_type = $decl->get_type() ?? TypeFactory::$_any;
			$type = TypeFactory::create_callable_type($return_type, $decl->parameters);
		}
		// elseif ($decl instanceof NamespaceDeclaration) {
		// 	$type = TypeFactory::$_namespace;
		// }
		// elseif ($decl === null && $this->is_weakly_checking) {
		// 	dump($node);
		// 	$type = TypeFactory::$_any;
		// }
		else {
			throw $this->new_syntax_error("Undexpected declaration for identifier '{$node->name}'", $node);
		}

		return $type;
	}

	private function infer_accessing_identifier(AccessingIdentifier $node): IType
	{
		$member = $this->require_accessing_identifier_declaration($node);
		switch ($member::KIND) {
			case MethodDeclaration::KIND:
			case FunctionDeclaration::KIND:
				$infered = TypeFactory::create_callable_type($member->get_type(), $member->parameters);
				break;

			case MaskedDeclaration::KIND:
				if ($member->parameters !== null) {
					throw $this->new_syntax_error("Cannot use the masked function '$member->name' without '()'", $node);
				}
				// unbreak
			case PropertyDeclaration::KIND:
			case ClassConstantDeclaration::KIND:
			case ObjectMember::KIND:

			case ClassDeclaration::KIND:
				$infered = $member->get_type();
				break;

			// case NamespaceDeclaration::KIND:
			// 	$infered = TypeFactory::$_namespace;
			// 	break;

			default:
				throw $this->new_syntax_error("Unexpected node", $member);
		}

		return $infered;
	}

	private function infer_regular_expression(RegularExpression $node): IType
	{
		return TypeFactory::$_regex;
	}

	private function infer_plain_interpolated_string(PlainInterpolatedString $node): IType
	{
		$is_pure = true;
		foreach ($node->items as $item) {
			if ($item instanceof StringInterpolation) {
				$infered = $this->infer_interpolation($item);
				if (!$infered instanceof IPureType) {
					$is_pure = false;
				}
			}
			elseif (!TeaHelper::is_pure_string($item)) {
				$is_pure = false;
			}
		}

		return $is_pure ? TypeFactory::$_pures : TypeFactory::$_string;
	}

	private function infer_escaped_interpolated_string(EscapedInterpolatedString $node): IType
	{
		foreach ($node->items as $item) {
			if ($item instanceof StringInterpolation) {
				$infered = $this->infer_interpolation($item);
				// $item->infered_type = $infered;
			}
		}

		return TypeFactory::$_string;
	}

	private function infer_variable_identifier(VariableIdentifier $node): IType
	{
		$decl = $this->get_actual_declaration_for_identifier($node);
		return $decl->get_type() ?? TypeFactory::$_any;
	}

	private function infer_xtag(XTag $node)
	{
		$fixed_attr_map = $node->fixed_attributes;
		$dyn_expr = $node->dynamic_attributes;
		$children = $node->children ?? [];

		foreach ($fixed_attr_map as $item) {
			if ($item instanceof XTagAttrInterpolation) {
				$infered = $this->infer_interpolation($item);
				// $item->infered_type = $infered;
			}
			elseif ($item instanceof BaseExpression) {
				$infered = $this->infer_expression($item);
				if (!TypeHelper::is_scalar_type($infered)) {
					$type_name = self::get_type_name($infered);
					throw $this->new_syntax_error("Expect scalar type value, {$type_name} given", $item);
				}
			}
			elseif ($item === true) {
				// true value item
			}
			else {
				throw $this->new_syntax_error("Invalid xtag attribute value", $item);
			}
		}

		if ($dyn_expr) {
			$infered = $this->infer_expression($dyn_expr);
			if (!$infered instanceof DictType) {
				throw $this->new_syntax_error("Type of activity attributes expression must be String.Dict", $dyn_expr);
			}
		}

		foreach ($children as $item) {
			if ($item instanceof XTag) {
				$this->infer_xtag($item);
			}
			elseif ($item instanceof XTagChildInterpolation) {
				$infered = $this->infer_interpolation($item);
				if (!TypeHelper::is_xtag_child_type($infered)) {
					$type_name = self::get_type_name($infered);
					throw $this->new_syntax_error("Expect String/XView/XView.Array type value, {$type_name} given", $item);
				}

				// $item->infered_type = $infered;
			}
			elseif ($item instanceof XTagElement) {
				// text/comment
			}
			else {
				// normal expression
				$infered = $this->infer_expression($item);
				if (!TypeHelper::is_scalar_type($infered)) {
					$type_name = self::get_type_name($infered);
					throw $this->new_syntax_error("Expect scalar type value, {$type_name} given", $item);
				}
			}
		}

		return TypeFactory::$_xview;
	}

	// protected function find_plain_symbol_and_check_declaration(PlainIdentifier $identifier)
	// {
	// 	$symbol = $this->find_symbol_for_plain_identifier($identifier);

	// 	if ($symbol === null && $identifier->name === _SUPER) {
	// 		$symbol = $this->get_symbol_for_super_identifier($identifier);
	// 	}

	// 	$symbol and $this->check_declaration_for_symbol($symbol);

	// 	// find in package level symbols
	// 	return $symbol;
	// }

	protected function find_type_symbol_and_check_declaration(IType $identifier)
	{
		$symbol = $identifier instanceof PlainIdentifier
			? $this->find_symbol_for_plain_identifier($identifier)
			: $this->find_symbol_for_type($identifier);

		if ($symbol === null && $identifier->name === _SUPER) {
			$symbol = $this->get_symbol_for_super_identifier($identifier);
		}

		$symbol and $this->check_declaration_for_symbol($symbol);

		// find in package level symbols
		return $symbol;
	}

	private function get_symbol_for_super_identifier(PlainIdentifier $identifier)
	{
		$current_method = $this->context_function;
		$super_identifier = $current_method->belong_block->extends[0] ?? null;
		if ($super_identifier === null) {
			// dump($current_method);
			throw $this->new_syntax_error("There are not extends a class/interface for 'super' reference", $identifier);
		}

		$super_class = $super_identifier->symbol->declaration;
		if ($current_method->is_static) {
			$symbol = $super_class->this_class_symbol;
		}
		else {
			$symbol = $super_class->this_object_symbol;
		}

		return $symbol;
	}

	private function get_actual_declaration_for_identifier(Identifiable $identifier)
	{
		$symbol = $identifier->symbol;
		if ($symbol === null) {
			if ($identifier->name === _SUPER) {
				$symbol = $this->get_symbol_for_super_identifier($identifier);
				$identifier->symbol = $symbol;
			}
			else {
				throw $this->new_syntax_error('Missed symbol', $identifier);
			}
		}

		$decl = $symbol->declaration;
		if (!$decl->is_checked) {
			$this->check_declaration($decl);
		}

		return $decl;
	}

	private function check_declaration_for_symbol(Symbol $symbol)
	{
		$decl = $symbol->declaration;
		if (!$decl->is_checked) {
			$this->check_declaration($decl);
		}
	}

	private function find_symbol_for_type(BaseType $type)
	{
		$name = $type->name;

		$symbol = $this->program->symbols[$name]
			?? $this->get_symbol_in_unit($this->unit, $name)
			?? ($this->builtin_unit ? $this->get_symbol_in_unit($this->builtin_unit, $name) : null);

		return $symbol;
	}

	private function find_symbol_for_plain_identifier(PlainIdentifier $identifier)
	{
		$name = $identifier->name;
		$based_ns = $identifier->ns;

		if ($based_ns) {
			$this->check_namespace($based_ns);
			$based_unit = $based_ns->based_unit;
			if ($based_unit === null) {
				// namespace mode
				$symbol = $this->find_symbol_in_namespace($based_ns, $name, $this->unit)
					?? $this->find_symbol_in_namespace($based_ns, $name, $this->builtin_unit);
			}
			else {
				// module mode
				$symbol = $this->get_symbol_in_unit($based_unit, $name);
			}
		}
		else {
			$symbol = $this->program->symbols[$name]
				?? $this->get_symbol_in_unit($this->unit, $name)
				?? ($this->builtin_unit ? $this->get_symbol_in_unit($this->builtin_unit, $name) : null);
		}

		return $symbol;
	}

	private function check_namespace(NamespaceIdentifier $ns)
	{
		$names = $ns->get_namepath();

		$found_unit = null;
		while ($names) {
			$uri = join(static::NS_SEPARATOR, $names);
			$found_unit = $this->unit->use_units[$uri] ?? null;
			if ($found_unit !== null) {
				break;
			}

			array_pop($names);
		}

		if ($found_unit) {
			$ns->set_based_unit($found_unit);
			$new_ns_names = $ns->get_namepath();
			$new_ns_names = array_slice($new_ns_names, count($found_unit->ns->names));
			$ns->set_names($new_ns_names);
		}
	}

	private function get_symbol_in_unit(Unit $unit, string $name)
	{
		$symbol = $unit->symbols[$name] ?? null;
		if ($symbol !== null) {
			$symbol->declaration->is_unit_level = true;
		}

		return $symbol;
	}

	private function get_unit_by_uri(string $uri)
	{
		return $this->unit->use_units[$uri] ?? null;
	}

	private function find_symbol_in_namespace(NamespaceIdentifier $ns, string $name, Unit $unit)
	{
		$decl = $this->find_namespace_declaration_in_unit($unit, $ns);
		return $decl->symbols[$name] ?? null;
	}

	private function find_namespace_declaration_in_unit(Unit $unit, NamespaceIdentifier $ns)
	{
		$namepath = $ns->get_namepath();
		$ns_name = array_shift($namepath);

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

	private function require_callee_declaration(BaseExpression $node): IDeclaration
	{
		$decl = null;
		if ($node instanceof AccessingIdentifier) {
			$decl = $this->require_accessing_identifier_declaration($node);
		}
		elseif ($node instanceof PlainIdentifier) {
			$decl = $this->require_callable_declaration($node);
		}
		elseif ($node instanceof ClassKindredIdentifier) {
			$decl = $this->get_checked_classkindred_declaration($node, true);
		}

		if ($decl === null) {
			$decl = $this->infer_expression($node);
			if (!$decl instanceof ICallableDeclaration) {
				if ($this->is_weakly_checking) {
					[$decl, $symbol] = $this->factory->create_virtual_function('__php_unknow_func');
				}
				else {
					$kind = $node::KIND;
					throw $this->new_syntax_error("Unknow callee kind: '$kind'", $node);
				}
			}
		}

		// if is a variable, it's value must be a callable declaration
		// eg. AnonymousFunction
		if ($decl instanceof IVariableDeclaration or $decl instanceof IConstantDeclaration) {
			$type = $decl->infered_type;
			if ($type instanceof MetaType) {
				$type = $type->generic_type;
			}

			$decl = $type->symbol->declaration;
		}

		return $decl;
	}

	private function require_callable_declaration(BaseExpression $node): ?IDeclaration
	{
		$decl = $this->get_actual_declaration_for_identifier($node);

		$return_type = $decl->get_type();
		if ($decl instanceof ICallableDeclaration) {
			$return_type === null && $this->check_callable_declaration($decl);
		}
		elseif ($return_type instanceof ICallableDeclaration) {
			$decl = $return_type;
		}
		elseif ($return_type === TypeFactory::$_callable) {
			// for Callable type parameters
		}
		elseif ($return_type instanceof MetaType and $return_type->generic_type instanceof ClassKindredIdentifier) {
			$decl = $this->get_checked_classkindred_declaration($return_type->generic_type, true);
		}
		else {
			// dump($return_type, $decl);
			// throw $this->new_syntax_error("Invalid callable expression", $node);
			$decl = null;
		}

		return $decl;
	}

	private function check_callable_declaration(ICallableDeclaration $node)
	{
		if ($node->is_checking) {
			throw $this->new_syntax_error("Function '{$node->name}' has a circular checking, needs a return type", $node);
		}

		$node->is_checking = true;

		switch ($node::KIND) {
			case MethodDeclaration::KIND:
				$this->check_method_declaration($node);
				break;

			case FunctionDeclaration::KIND:
				$this->check_function_declaration($node);
				break;

			case AnonymousFunction::KIND:
				$this->infer_anonymous_function($node);
				break;

			// case CallbackProtocol::KIND:
			// 	break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unexpect callable declaration kind: '{$kind}'", $node);
		}
	}

	// includes BuiltinTypeClassDeclaration,
	private function require_accessing_identifier_declaration(AccessingIdentifier $node): IDeclaration
	{
		$basing = $node->basing;
		$basing_type = $this->infer_expression($basing);

		if ($basing_type instanceof BaseType) {
			$this->attach_symbol_for_basetype_accessing_identifier($node, $basing_type);
		}
		elseif ($basing_type instanceof Identifiable) {
			$basing_declar = $this->get_actual_class_declaration_for_metatype_expr($basing_type);
			$node->symbol = $this->require_class_member_symbol($basing_declar, $node);
			if (!$this->is_instance_accessable($basing, $node->symbol->declaration)) {
				throw $this->new_syntax_error("Cannot access private/protected members", $node);
			}
		}
		else {
			$type_name = $this->get_type_name($basing_type);
			throw $this->new_syntax_error("Invalid accessable type '$type_name'", $basing);
		}

		$decl = $node->symbol->declaration;
		return $decl;
	}

	private function get_actual_class_declaration_for_metatype_expr(Identifiable $identifier): ClassKindredDeclaration
	{
		// the master would be an object expression, or variable of MetaType
		$decl = $identifier->symbol->declaration;
		if ($decl instanceof VariableDeclaration) {
			$decl = $decl->get_type()->generic_type->symbol->declaration;
		}

		return $decl;
	}

	private function attach_symbol_for_basetype_accessing_identifier(AccessingIdentifier $node, BaseType $basing_type)
	{
		// if ($basing_type === TypeFactory::$_any) {
		// 	// let member type to Any on master is Any
		// 	$this->create_any_symbol_for_accessing_identifier($node);
		// }
		// elseif ($basing_type === TypeFactory::$_namespace) {
		// 	$this->attach_namespace_member_symbol($node->basing->symbol->declaration, $node);
		// }
		if ($basing_type instanceof MetaType) {
			$this->attach_symbol_for_metatype_accessing_identifier($node, $basing_type);
		}
		elseif ($basing_type instanceof UnionType) {
			throw $this->new_syntax_error("Cannot accessing the 'UnionType' targets", $node);
		}
		else {
			$basing_symbol = $basing_type->symbol;
			if ($basing_symbol === null) {
				throw $this->new_syntax_error("Symbol not setted, it's seems a bug in checker", $node);
			}

			$basing_decl = $basing_symbol->declaration;
			// $symbol = $this->find_member_symbol_in_class_declaration($basing_decl, $node->name);
			// if ($symbol === null) {
			// 	if ($basing_decl->is_virtual || $this->is_type_as_dynamic_class($basing_type)) {
			// 		[$decl, $symbol] = $this->factory->create_virtual_property($node->name, $basing_decl);
			// 	}
			// 	else {
			// 		throw $this->new_syntax_error("Member '{$node->name}' not found in '{$basing_decl->name}'", $node);
			// 	}
			// }

			$symbol = $this->require_class_member_symbol($basing_decl, $node);
			$node->symbol = $symbol;
		}
	}

	private function is_type_as_dynamic_class(IType $type)
	{
		return $this->is_weakly_checking && ($type instanceof AnyType || $type instanceof StringType);
	}

	private function attach_symbol_for_metatype_accessing_identifier(AccessingIdentifier $node, MetaType $basing_type) {
		$basing_decl = $basing_type->generic_type->symbol->declaration;

		// find static member for classes
		// $symbol = $this->find_member_symbol_in_class_declaration($basing_decl, $node->name);
		// if ($symbol === null) {
		// 	if ($this->is_weakly_checking) {
		// 		[$decl, $symbol] = $this->factory->create_virtual_method($node->name, $basing_decl);
		// 		$decl->is_static = $node->is_static;
		// 	}
		// 	else {
		// 		throw $this->new_syntax_error("Member '{$node->name}' not found in '{$decl->name}'", $node);
		// 	}
		// }

		$symbol = $this->require_class_member_symbol($basing_decl, $node);

		// check static member

		$node->symbol = $symbol;
		$node_declaration = $symbol->declaration;
		if (!$node_declaration->is_static) {
			throw $this->new_syntax_error("Invalid to accessing a non-static member", $node);
		}

		if (!$this->is_static_accessable($node->basing, $node_declaration)) {
			throw $this->new_syntax_error("Cannot accessing the private/protected members", $node);
		}
	}

	private function is_instance_accessable(BaseExpression $expr, IClassMemberDeclaration $member) {
		if ($member->modifier === _PRIVATE) {
			$accessable = $expr instanceof PlainIdentifier && $expr->symbol === $member->belong_block->this_object_symbol;
		}
		elseif ($member->modifier === _PROTECTED) {
			$accessable = $expr instanceof PlainIdentifier && ($expr->name === _THIS || $expr->name === _SUPER);
		}
		else {
			$accessable = true;
		}

		return $accessable;
	}

	private function is_static_accessable(BaseExpression $expr, IClassMemberDeclaration $member) {
		if ($member->modifier === _PRIVATE) {
			$accessable = $expr instanceof PlainIdentifier && $expr->symbol === $member->belong_block->this_class_symbol;
		}
		elseif ($member->modifier === _PROTECTED) {
			$accessable = $expr instanceof PlainIdentifier && ($expr->name === _THIS || $expr->name === _SUPER);
		}
		else {
			$accessable = true;
		}

		return $accessable;
	}

	// private function create_any_symbol_for_accessing_identifier(AccessingIdentifier $node)
	// {
	// 	$node->symbol = new Symbol(ASTFactory::$virtual_property_for_any, $node->name);
	// }

	private function find_member_symbol_in_class_declaration(ClassKindredDeclaration $classkindred, string $name): ?Symbol
	{
		// when is super member
		$symbol = $classkindred->aggregated_members[$name] ?? null;
		if ($symbol) {
			$decl = $symbol->declaration;
			if (!$decl->is_checked) {
				// switch to target program
				$temp_program = $this->program;
				$this->program = $classkindred->program;

				$this->check_class_member_declaration($decl);

				// switch back
				$this->program = $temp_program;
			}
		}

		return $symbol;

		// when is self member

		// // find in self
		// $symbol = $classkindred->symbols[$name] ?? $classkindred->trait_members[$name] ?? null;
		// if ($symbol) {
		// 	$decl = $symbol->declaration;
		// 	if (!$decl->is_checked) {
		// 		// switch to target program
		// 		$temp_program = $this->program;
		// 		$this->program = $classkindred->program;

		// 		$this->check_class_member_declaration($decl);

		// 		// switch back
		// 		$this->program = $temp_program;
		// 	}

		// 	return $symbol;
		// }

		// // find in extends
		// foreach ($classkindred->extends as $based_identifier) {
		// 	$member_symbol = $this->find_member_symbol_in_class_declaration($based_identifier->symbol->declaration, $name);
		// 	if ($member_symbol) {
		// 		return $member_symbol;
		// 	}
		// }

		// // find in implements
		// foreach ($classkindred->implements ?? [] as $based_identifier) {
		// 	$member_symbol = $this->find_member_symbol_in_interface_identifier($based_identifier, $name);
		// 	if ($member_symbol) {
		// 		return $member_symbol;
		// 	}
		// }

		// return null;
	}

	// private function find_member_symbol_in_interface_identifier(ClassKindredIdentifier $interface, string $name)
	// {
	// 	if ($interface->symbol) {
	// 		$interface_decl = $interface->symbol->declaration;
	// 	}
	// 	else {
	// 		$interface_decl = $this->get_checked_classkindred_declaration($interface);
	// 		if ($interface_decl === null) {
	// 			return null;
	// 		}
	// 	}

	// 	return $this->find_member_symbol_in_class_declaration($interface_decl, $name);
	// }

	private function require_class_member_symbol(ClassKindredDeclaration $class_decl, AccessingIdentifier $node): Symbol
	{
		$name = $node->name;
		$symbol = $this->find_member_symbol_in_class_declaration($class_decl, $name);
		if ($symbol === null) {
			if ($node->is_static && $name === _CLASS) {
				[$decl, $symbol] = $this->factory->create_virtual_class_constant($name, TypeFactory::$_pures, $class_decl);
			}
			else {
				$symbol = $this->try_create_virtual_member_symbol($class_decl, $node);
				if ($symbol === null) {
					throw $this->new_syntax_error("Member '{$name}' not found in '{$class_decl->name}'", $node);
				}
			}

			$symbol->declaration->is_static = $node->is_static;
		}

		return $symbol;
	}

	private function try_create_virtual_member_symbol(ClassKindredDeclaration $class_decl, AccessingIdentifier $node)
	{
		$symbol = null;
		if ($node->is_invoking()) {
			if ($this->can_use_feature(ClassFeature::MAGIC_CALL, $class_decl)) {
				[$decl, $symbol] = $this->factory->create_virtual_method($node->name, $class_decl);
			}
		}
		elseif ($node->is_assigning()) {
			if ($this->can_use_feature(ClassFeature::MAGIC_SET, $class_decl)) {
				[$decl, $symbol] = $this->factory->create_virtual_property($node->name, $class_decl);
			}
		}
		else {
			if ($this->can_use_feature(ClassFeature::MAGIC_GET, $class_decl)) {
				[$decl, $symbol] = $this->factory->create_virtual_property($node->name, $class_decl);
			}
		}

		return $symbol;
	}

	private function can_use_feature(ClassFeature $feature, ClassKindredDeclaration $decl)
	{
		if ($decl->is_virtual || $decl->has_feature($feature)) {
			$can = true;
		}
		elseif ($this->is_weakly_checking) {
			$can = $decl instanceof TraitDeclaration || in_array($decl->name, static::DYNAMIC_CLASS_NAMES);
		}
		else {
			$can = false;
		}

		return $can;
	}

	// private function attach_namespace_member_symbol(NamespaceDeclaration $ns_declaration, AccessingIdentifier $node)
	// {
	// 	$node->symbol = $ns_declaration->symbols[$node->name] ?? null;
	// 	if (!$node->symbol) {
	// 		throw $this->new_syntax_error("Symbol '{$node->name}' not found in '{$ns_declaration->name}'", $node);
	// 	}

	// 	return $node->symbol;
	// }

	private function get_class_declaration_for_expr(BaseExpression $expr)
	{
		$type = $this->infer_expression($expr);

		$symbol = $type->symbol;
		$decl = $symbol->declaration;

		if ($this->is_type_as_dynamic_class($type)) {
			[$decl, ] = $this->factory->create_virtual_class($symbol->name, $this->program);
			$symbol->declaration = $decl;
		}
		else {
			$decl = $this->filter_classkindred_declaration($decl, $type, $expr);
		}

		return $decl;
	}

	private function get_unchecked_classkindred_declaration(PlainIdentifier $identifier)
	{
		$symbol = $identifier->symbol;
		if ($symbol === null) {
			throw $this->new_syntax_error("Symbol of identifier '{$identifier->name}' not linked", $identifier);
		}

		$decl = $symbol->declaration;
		if (!$decl instanceof ClassKindredDeclaration) {
			throw $this->new_syntax_error("Declaration of identifier '{$identifier->name}' not classkindred", $identifier);
		}

		$decl->is_linked || $this->link_classkindred_declaration($decl);
		return $decl;
	}

	private function get_checked_classkindred_declaration(PlainIdentifier $identifier, bool $required = false)
	{
		$symbol = $identifier->symbol;
		if ($symbol === null) {
			throw $this->new_syntax_error("Unexpected classkindred identifier", $identifier);
		}

		$decl = $symbol->declaration;
		$type = $decl->get_hinted_type();

		if ($this->is_type_as_dynamic_class($type)) {
			[$decl, ] = $this->factory->create_virtual_class($symbol->name, $this->program);
			$symbol->declaration = $decl;
		}
		else {
			$decl = $this->filter_classkindred_declaration($decl, $type, $identifier);
		}

		return $decl;
	}

	private function filter_classkindred_declaration(IDeclaration $decl, IType $type, BaseExpression $expr)
	{
		if ($decl instanceof BaseVariableDeclaration && $type instanceof MetaType) {
			$decl = $type->generic_type->symbol->declaration;
		}

		if ($decl instanceof ClassKindredDeclaration) {
			if (!$decl->is_checked) {
				$temp_program = $this->program;
				$this->program = $decl->program;
				$this->check_classkindred_declaration($decl);
				$this->program = $temp_program;
			}
		}
		else {
			$message = $expr instanceof PlainIdentifier
				? "Type of expression not classkindred"
				: "Declaration of '{$identifier->name}' not classkindred";
			throw $this->new_syntax_error($message, $expr);
		}

		return $decl;
	}

	// private function require_namespace_declaration_in_unit(NamespaceIdentifier $identifier)
	// {
	// 	$decl = $this->find_namespace_declaration_in_unit($this->unit, $identifier)
	// 		?? $this->find_namespace_declaration_in_unit($this->builtin_unit, $identifier);
	// 	if ($decl === null) {
	// 		throw $this->new_syntax_error("Namespace '{$identifier->uri}' not found in package '{$this->unit->uri}'", $identifier);
	// 	}

	// 	return $decl;
	// }

	protected function link_source_declaration_for_use(UseDeclaration $use, Unit $unit)
	{
		$name = $use->source_name ?? $use->target_name;
		if ($name) {
			// the use targets mode
			// find from the Unit symbols
			$symbol = $unit->symbols[$name] ?? null;
			if ($symbol === null) {
				throw $this->new_syntax_error("Target '{$name}' for use not found in package '{$unit->name}'", $use);
			}

			$target_declaration = $symbol->declaration;
			// if (!$target_declaration->is_checked) {
			// 	self::get_checker($target_declaration->program)->check_declaration($target_declaration);
			// }
		}
		else {
			// the use namespace self mode
			$target_declaration = $unit;
		}

		$use->source_declaration = $target_declaration;
		$use->is_checked = true;
	}

	protected function get_uses_unit_declaration(NamespaceIdentifier $ns): ?Unit
	{
		$unit = $this->get_unit_by_uri($ns->uri);
		if ($unit === null) {
			// for PHP uses
			if ($ns->uri === $this->unit->name) {
				$unit = $this->unit;
			}
		}

		return $unit;
	}

	private function reduce_types(array $types): ?IType
	{
		$count = count($types);

		$nullable = false;
		$result_type = null;
		for ($i = 0; $i < $count; $i++) {
			$result_type = $types[$i];
			if ($result_type === null || $result_type === TypeFactory::$_none) {
				$nullable = true;
			}
			else {
				break;
			}
		}

		if ($result_type !== TypeFactory::$_any) {
			for ($i = $i + 1; $i < $count; $i++) {
				$type = $types[$i];
				if ($type === null || $type === TypeFactory::$_none) {
					$nullable = true;
				}
				elseif ($type === TypeFactory::$_any) {
					$result_type = $type;
					break;
				}
				else {
					$result_type = $result_type->unite_type($type);
				}
			}

			if ($nullable && $result_type) {
				$result_type = $result_type->get_nullable_instance();
			}
		}

		return $result_type;
	}

	protected function new_syntax_error($message, $node)
	{
		if ($node->pos) {
			$place = $this->program->parser->get_error_place_with_pos($node->pos);
		}
		else {
			$place = get_class($node);
			if (isset($node->name)) {
				$place .= " of '$node->name'";
			}

			$place = "Near $place on check {$this->program->name}";
		}

		$message = "Syntax check error:\n{$place}\n{$message}";
		DEBUG && $message .= "\n\nTraces:\n" . get_traces();

		return new Exception($message);
	}

	static function get_declaration_name(IDeclaration $decl)
	{
		if (isset($decl->belong_block) && $decl->belong_block instanceof ClassDeclaration) {
			return "{$decl->belong_block->name}.{$decl->name}";
		}

		return $decl->name;
	}

	static function get_type_name(IType $type)
	{
		if ($type instanceof IterableType) {
			if ($type->generic_type === null) {
				$name = $type->name;
			}
			else {
				$generic_type_name = self::get_type_name($type->generic_type);
				if (strpos($generic_type_name, '|')) {
					$generic_type_name = "($generic_type_name)";
				}

				$name = "{$generic_type_name}.{$type->name}";
			}
		}
		elseif ($type instanceof MetaType) {
			$generic_type_name = self::get_type_name($type->generic_type);
			$name = "{$generic_type_name}." . _DOT_SIGN_METATYPE;
		}
		elseif ($type instanceof ICallableDeclaration) {
			$args = [];
			foreach ($type->parameters as $param) {
				$args[] = static::get_type_name($param->get_type());
			}

			$name = 'callable "(' . join(', ', $args) . ') ' . static::get_type_name($type->get_type()) . '"';
		}
		elseif ($type instanceof UnionType) {
			$names = [];
			foreach ($type->get_members() as $member) {
				$names[] = static::get_type_name($member);
			}

			$name = join('|', $names);
		}
		else {
			$name = $type ? static::get_identifier_name($type) : '-';
		}

		if ($type->nullable) {
			$name .= '?';
		}

		return $name;
	}

	static function get_identifier_name(IType $identifier)
	{
		$name = $identifier->name;
		if ($identifier instanceof ClassKindredIdentifier and $identifier->ns) {
			$names = $identifier->ns->names;
			$names[] = $name;
			$name = join(static::NS_SEPARATOR, $names);
		}

		return $name;
	}
}

// end
