<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ASTChecker
{
	const NS_SEPARATOR = TeaParser::NS_SEPARATOR;

	protected $is_weakly_typed_system = false;

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

	private $current_function;

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
	}

	public function collect_program_uses(Program $program)
	{
		$this->program = $program;

		foreach ($program->declarations as $node) {
			$this->collect_declaration_uses($node);
		}

		$program->initializer && $this->collect_declaration_uses($program->initializer);
	}

	private function collect_declaration_uses(IDeclaration $declaration)
	{
		foreach ($declaration->defer_check_identifiers as $identifier) {
			$symbol = $identifier->symbol ?? $this->find_symbol_for_plain_identifier($identifier);
			if ($symbol === null) {
				throw $this->new_syntax_error("Symbol of '{$identifier->name}' not found when check declaration uses", $identifier);
			}

			$dependence = $symbol->declaration;
			if ($dependence instanceof UseDeclaration) {
				$declaration->append_use_declaration($dependence);
			}
		}
	}

	public function check_program(Program $program)
	{
		if ($program->is_checked) return;
		$program->is_checked = true;

		$this->program = $program;

		foreach ($program->use_targets as $target) {
			$this->check_use_target($target);
		}

		foreach ($program->declarations as $node) {
			$this->check_declaration($node);
		}

		if ($program->initializer) {
			$this->check_declaration($program->initializer);
		}
	}

	protected function check_use_target(UseDeclaration $node)
	{
		$this->get_source_declaration_for_use($node);
	}

	private function check_declaration(IDeclaration $node)
	{
		if ($node->is_checked) {
			return $node;
		}

		$temp_program = $this->program;
		$this->program = $node->program;

		switch ($node::KIND) {
			case FunctionDeclaration::KIND:
				$this->check_function_declaration($node);
				break;

			case BuiltinTypeClassDeclaration::KIND:
			case ClassDeclaration::KIND:
			case IntertraitDeclaration::KIND:
			case TraitDeclaration::KIND:
			case InterfaceDeclaration::KIND:
				$this->check_local_classkindred_declaration($node);
				break;

			case ConstantDeclaration::KIND:
				$this->check_constant_declaration($node);
				break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unexpect declaration kind: '{$kind}'", $node);
		}

		$this->program = $temp_program;
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
			if ($noted and !$declared->is_accept_type($noted)) {
				$noted_name = self::get_type_name($noted);
				$declared_name = self::get_type_name($declared);
				throw $this->new_syntax_error("The noted return type is '{$noted_name}', do not compatibled with the declared '{$declared_name}'", $node);
			}
		}

		return $noted ?? $declared;
	}

	private function check_constant_declaration(IConstantDeclaration $node)
	{
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
			elseif ($value->operator->is(OPID::MERGE)) {
				throw $this->new_syntax_error("Dict merge operation cannot use for constant value", $value);
			}
		}
	}

	private function is_const_expr(BaseExpression $node): bool
	{
		// if ($node instanceof ILiteral || $node instanceof ConstantIdentifier) {
		// 	$result = true;
		// }
		// else
		if ($node instanceof Identifiable) {
			$declaration = $node->symbol->declaration;
			$result = $declaration instanceof IConstantDeclaration || $declaration instanceof ClassKindredDeclaration;
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
			else {
				$infered = $hinted;
			}
		}
		elseif ($infered === TypeFactory::$_uint && $node->value instanceof LiteralInteger) {
			// set infered type to Int when value is Integer literal
			$infered = TypeFactory::$_int;
		}
		elseif ($infered === null || $infered instanceof NoneType) {
			$infered = TypeFactory::$_any;
		}

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

	// private function check_expect_declaration(ExpectDeclaration $node)
	// {
	// 	$this->is_checked = true;
	// 	$this->check_parameters_for_node($node);
	// }

	// private function check_coroutine_block(CoroutineBlock $node)
	// {
	// 	// check for use variables
	// 	foreach ($node->defer_check_identifiers as $identifier) {
	// 		if (!$identifier->symbol) {
	// 			$this->infer_plain_identifier($identifier);
	// 		}

	// 		if ($identifier->symbol->declaration instanceof IVariableDeclaration) {
	// 			if ($identifier->name === _THIS || $identifier->name === _SUPER) {
	// 				throw $this->new_syntax_error("'{$identifier->name}' cannot use in coroutine block", $node);
	// 			}

	// 			// for lambda use in php
	// 			$node->use_variables[$identifier->name] = $identifier;
	// 		}
	// 	}

	// 	$this->check_parameters_for_callable_declaration($node);

	// 	$infered = $this->infer_scope_block_body($node);
	// }

	private function infer_anonymous_function(AnonymousFunction $node)
	{
		// check for use variables
		foreach ($node->defer_check_identifiers as $identifier) {
			if (!$identifier->symbol) {
				$this->infer_plain_identifier($identifier);
			}

			if ($identifier->symbol->declaration instanceof IVariableDeclaration) {
				if ($identifier->name === _THIS || $identifier->name === _SUPER) {
					throw $this->new_syntax_error("'{$identifier->name}' cannot use in lambda functions", $node);
				}

				// for lambda use in php
				$node->use_variables[$identifier->name] = $identifier;
			}
		}

		$this->check_parameters_for_callable_declaration($node);

		$hinted = $this->get_and_check_hinted_type($node);
		$infered = $this->infer_scope_block_body($node);

		$node->infered_type = $infered ?? $hinted ?? TypeFactory::$_void;

		return TypeFactory::create_callable_type($node->get_type(), $node->parameters);
	}

	private function infer_scope_block_body(IScopeBlock $node)
	{
		if (is_array($node->body)) {
			$infered = $this->infer_block($node);
		}
		else {
			$infered = $this->infer_single_expression_block($node);
		}

		$hinted = $node->get_hinted_type();
		if ($hinted) {
			if ($infered !== null) {
				if ($hinted === TypeFactory::$_self) {
					$declar = $node->belong_block;
					$actual_type = new ClassKindredIdentifier($declar->name);
					$actual_type->symbol = $declar->symbol;
					$hinted = $actual_type;
				}

				if (!$hinted->is_accept_type($infered)) {
					$infered_name = self::get_type_name($infered);
					$hinted_name = self::get_type_name($hinted);
					throw $this->new_syntax_error("The infered return type is '{$infered_name}', do not compatibled with the hinted '{$hinted_name}'", $node);
				}
			}
			elseif ($hinted !== TypeFactory::$_void && $hinted !== TypeFactory::$_yield_generator) {
				throw $this->new_syntax_error("Function required a return type '{$hinted->name}'", $node);
			}
		}

		return $infered;
	}

	protected function check_function_declaration(FunctionDeclaration $node)
	{
		if ($node->is_checked) return;
		$node->is_checked = true;

		$this->check_parameters_for_callable_declaration($node);

		$hinted = $this->get_and_check_hinted_type($node);

		if ($node->body !== null) {
			$infered = $this->infer_scope_block_body($node);
		}

		$node->infered_type = $infered ?? $hinted ?? TypeFactory::$_void;
	}


	protected function check_method_declaration(MethodDeclaration $node)
	{
		if ($node->is_checked) return;
		$node->is_checked = true;

		$this->check_parameters_for_callable_declaration($node);

		$hinted = $this->get_and_check_hinted_type($node);

		if ($node->body !== null) {
			$this->current_function = $node; // for find super
			$infered = $this->infer_scope_block_body($node);
		}

		$node->infered_type = $infered ?? $hinted ?? TypeFactory::$_void;
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

	private function check_local_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$node->is_checked = true;

		// when it is currently a class, including inherited classes or implemented interfaces
		// when it is currently an interface, including inherited interfaces
		if ($node->bases) {
			$this->attach_bases_for_classkindred_declaration($node);
		}

		// first check the members in this class, and the inferred types will be used later
		foreach ($node->members as $member) {
			$this->check_class_member_declaration($member);
		}

		// check is has default implementations for Intertrait
		// if ($node instanceof IntertraitDeclaration) {
		// 	foreach ($node->members as $member) {
		// 		if ($member instanceof PropertyDeclaration || ($member instanceof MethodDeclaration && $member->body !== null)) {
		// 			$node->has_default_implementations = true;
		// 		}
		// 	}
		// }

		if ($node->inherits) {
			$this->check_inherits_for_class_declaration($node);
		}

		if ($node->bases) {
			$this->check_bases_for_classkindred_declaration($node);
		}

		// the members in this class have the highest priority
		if ($node->members) {
			$node->aggregated_members = array_merge($node->aggregated_members, $node->members);
		}
	}

	private function attach_bases_for_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$interfaces = [];
		foreach ($node->bases as $identifier) {
			$declaration = $this->get_classkindred_declaration($identifier);

			if ($identifier->generic_types) {
				$this->check_generic_types($identifier);
			}

			if ($declaration instanceof ClassDeclaration) {
				if ($node instanceof InterfaceDeclaration) {
					throw $this->new_syntax_error("Cannot to inherits super class for interface '{$node->name}'", $node);
				}

				if ($node->inherits) {
					throw $this->new_syntax_error("Only one super class could be inherits for class '{$node->name}'", $node);
				}

				$node->inherits = $identifier;
			}
			else {
				$interfaces[] = $identifier;
			}
		}

		if ($node->inherits) {
			$node->bases = $interfaces;
		}
	}

	private function check_generic_types(PlainIdentifier $identifier) {
		foreach ($identifier->generic_types as $key => $type) {
			$this->check_type($type);
		}
	}

	private function check_inherits_for_class_declaration(ClassDeclaration $node)
	{
		// for checking php
		if ($node->inherits->symbol === null) {
			$this->get_classkindred_declaration($node->inherits, true);
		}

		// Added to the actual members of this class
		// inherited members belong to super and have the lowest priority
		$node->aggregated_members = $node->inherits->symbol->declaration->aggregated_members ?? [];

		// check if there are overridden members in this class that match the parent class members
		foreach ($node->aggregated_members as $name => $super_class_member) {
			if (isset($node->members[$name])) {
				// check super class member declared in current class
				$this->assert_member_declarations($node->members[$name], $super_class_member);
			}
		}
	}

	private function check_bases_for_classkindred_declaration(ClassKindredDeclaration $node)
	{
		// The default implementation of members in the interface belongs to 'this' and has a higher priority.
		// The default implementation of subsequent interfaces will override the previous ones
		foreach ($node->bases as $identifier) {
			$interface = $identifier->symbol->declaration;
			foreach ($interface->aggregated_members as $name => $member) {
				if (isset($node->members[$name])) {
					// check member declared in current class/interface
					$this->assert_member_declarations($node->members[$name], $member, true);
				}
				elseif (isset($node->aggregated_members[$name])) {
					// check member declared in bases class/interfaces
					$this->assert_member_declarations($node->aggregated_members[$name], $member, true);

					// replace to the default method implementation in interface
					if ($member instanceof MethodDeclaration && $member->body !== null) {
						$node->aggregated_members[$name] = $member;
					}
				}
				else {
					$node->aggregated_members[$name] = $member;
				}
			}
		}

		// If it is a class definition, finally check if there are any unimplemented interface members
		if ($node instanceof ClassDeclaration && $node->define_mode) {
			foreach ($node->aggregated_members as $name => $member) {
				if ($member instanceof MethodDeclaration && $member->body === null) {
					$interface = $member->belong_block;
					throw $this->new_syntax_error("Method protocol '{$interface->name}.{$name}' required an implementation in class '{$node->name}'", $node);
				}
			}
		}
	}

	protected function assert_member_declarations(IClassMemberDeclaration $node, IClassMemberDeclaration $super, bool $is_interface = false)
	{
		// do not need check for construct
		if ($node->name === _CONSTRUCT) {
			return;
		}

		// check types
		$node_type = $node->get_type();
		$super_type = $super->get_type();
		if (!$super_type->is_accept_type($node_type)) {
			$node_hinted_type = $this->get_type_name($node_type);
			$super_hinted_type = $this->get_type_name($super_type);
			throw $this->new_syntax_error("Type '{$node_hinted_type}' in '{$node->belong_block->name}.{$node->name}' is incompatible with '$super_hinted_type' in '{$super->belong_block->name}.{$super->name}'", $node);
		}

		// the accessing modifer
		$super_modifier = $super->modifier ?? _PUBLIC;
		$this_modifier = $node->modifier ?? _PUBLIC;
		if ($super_modifier !== $this_modifier) {
			throw $this->new_syntax_error("Modifier in '{$node->belong_block->name}.{$node->name}' must be same as '{$super->belong_block->name}.{$super->name}'", $node->belong_block);
		}

		if ($super instanceof MethodDeclaration) {
			if (!$node instanceof MethodDeclaration) {
				throw $this->new_syntax_error("Kind of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$super->belong_block->name}.{$super->name}'", $node);
			}

			// check type hint
			if ($super_type and !$node_type) {
				$super_hinted_type = $this->get_type_name($super_type);
				throw $this->new_syntax_error("There are declared type '{$super_hinted_type}' in '{$super->belong_block->name}.{$super->name}', but not in '{$node->belong_block->name}.{$node->name}'", $node);
			}

			$this->assert_classkindred_method_parameters($node, $super);
		}
		elseif ($super instanceof PropertyDeclaration) {
			if (!$node instanceof PropertyDeclaration) {
				throw $this->new_syntax_error("Kind of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$super->belong_block->name}.{$super->name}'", $node);
			}
		}
		elseif ($super instanceof ClassConstantDeclaration && $is_interface) {
			throw $this->new_syntax_error("Cannot override interface constant '{$super->belong_block->name}.{$super->name}' in '{$node->belong_block->name}'", $node);
		}
	}

	private function assert_classkindred_method_parameters(MethodDeclaration $node, MethodDeclaration $protocol)
	{
		if ($protocol->parameters === null && $protocol->parameters === null) {
			return;
		}

		// the parameters count
		if (count($protocol->parameters) !== count($node->parameters)) {
			throw $this->new_syntax_error("Parameters of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$protocol->belong_block->name}.{$protocol->name}'", $node);
		}

		// the parameter types
		foreach ($protocol->parameters as $idx => $protocol_param) {
			$node_param = $node->parameters[$idx];
			if (!$this->is_strict_compatible_types($protocol_param->get_type(), $node_param->get_type())) {
				$type_name = $this->get_type_name($node_param->get_type());
				throw $this->new_syntax_error("Parameter '{$node_param->name} {$type_name}' in '{$node->belong_block->name}.{$node->name}' is incompatible with '{$protocol->belong_block->name}.{$protocol->name}'", $node_param);
			}
		}
	}

	private function infer_if_block(IfBlock $node): ?IType
	{
		$result_type = $this->infer_base_if_block($node);

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_base_if_block(BaseIfBlock $node): ?IType
	{
		$this->infer_expression($node->condition);

		if ($node->condition instanceof BinaryOperation and $node->condition->type_assertion !== null) {
			// with type assertion
			$result_type = $this->infer_base_if_block_with_assertion($node);
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

	private function infer_base_if_block_with_assertion(BaseIfBlock $node): ?IType
	{
		$type_assertion = $node->condition->type_assertion;

		// cannot use type assertion when not an Identifiable
		if (!$type_assertion->left instanceof Identifiable) {
			// check block body
			$result_type = $this->infer_block($node);
			if ($node->else) {
				$result_type = $this->reduce_types_with_else_block($node, $result_type);
			}

			return $result_type;
		}

		$left_declaration = $type_assertion->left->symbol->declaration;
		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = $left_declaration->infered_type;
		$asserting_type = $type_assertion->right;

		if ($type_assertion->not) {
			if ($left_original_type instanceof UnionType) {
				$asserted_then_type = $left_original_type->get_members_type_except($asserting_type);
			}

			$asserted_else_type = $asserting_type;
		}
		else {
			if ($left_original_type instanceof UnionType) {
				$asserted_else_type = $left_original_type->get_members_type_except($asserting_type);
			}

			$asserted_then_type = $asserting_type;
		}

		// it would infer with the asserted then type
		if ($asserted_then_type) {
			$left_declaration->infered_type = $asserted_then_type;
		}

		// check block body
		$result_type = $this->infer_block($node);

		// if assert none, and returned, means removed null
		if ($node->is_returned and $asserted_then_type instanceof NoneType and $left_original_type->has_null) {
			$left_original_type->has_null = false;
		}

		if ($node->else) {
			// it would infer with the asserted else type
			$left_declaration->infered_type = $asserted_else_type ?? $left_original_type;
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		// reset to original type
		$left_declaration->infered_type = $left_original_type;

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

	protected function infer_except_block(IExceptBlock $node): ?IType
	{
		if ($node instanceof CatchBlock) {
			$this->check_variable_declaration($node->var);
			$result_type = $this->infer_block($node);

			if ($node->except) {
				$result_type = $this->reduce_types_with_except_block($node, $result_type);
			}
		}
		else {
			$result_type = $this->infer_block($node);
		}

		return $result_type;
	}

	private function reduce_types_with_except_block(IExceptAble $node, ?IType $previous_type)
	{
		$infered = $this->infer_except_block($node->except);
		if ($previous_type) {
			return $this->reduce_types([$previous_type, $infered]);
		}

		return $infered;
	}

	private function infer_switch_block(SwitchBlock $node): ?IType
	{
		$matching_type = $this->infer_expression($node->test);
		if (!TypeHelper::is_case_testable_type($matching_type)) {
			$matching_type_name = self::get_type_name($matching_type);
			throw $this->new_syntax_error("The case compare expression should be String/Int/UInt, $matching_type_name given", $node->test);
		}

		$infereds = [];
		foreach ($node->branches as $branch) {
			if ($branch->rule instanceof ExpressionList) {
				foreach ($branch->rule->items as $rule_sub_expr) {
					$case_type = $this->infer_expression($rule_sub_expr);
					if (!TypeHelper::is_switch_compatible($matching_type, $case_type)) {
						$matching_type_name = self::get_type_name($matching_type);
						$case_type_name = self::get_type_name($case_type);
						throw $this->new_syntax_error("Incompatible matching types, matching type is $matching_type_name, case type is $case_type_name", $rule_sub_expr);
					}
				}
			}
			else {
				$case_type = $this->infer_expression($branch->rule);
				if (!TypeHelper::is_switch_compatible($matching_type, $case_type)) {
					$matching_type_name = self::get_type_name($matching_type);
					$case_type_name = self::get_type_name($case_type);
					throw $this->new_syntax_error("Incompatible matching types, matching type is $matching_type_name, case type is $case_type_name", $branch->rule);
				}
			}

			$infereds[] = $this->infer_block($branch);
		}

		$result_type = $infereds ? $this->reduce_types($infereds) : null;

		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_forin_block(ForInBlock $node): ?IType
	{
		$expr_type = $this->infer_expression($node->iterable);
		$element_types = $this->infer_iter_element_types($expr_type);
		if ($element_types === null) {
			$type_name = self::get_type_name($expr_type);
			throw $this->new_syntax_error("Expect scalar type value, {$type_name} given", $node->iterable);
		}

		[$key_type, $val_type] = $element_types;

		if ($node->key) {
			$node->key->symbol->declaration->infered_type = $key_type;
		}

		$node->val->symbol->declaration->infered_type = $val_type;

		/// ---

		$result_type = $this->infer_block($node);

		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
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
		$start_type = $this->expect_infered_type($node->start, TypeFactory::$_uint, TypeFactory::$_int);
		$end_type = $this->expect_infered_type($node->end, TypeFactory::$_uint, TypeFactory::$_int);

		if ($node->key) {
			$node->key->symbol->declaration->infered_type = TypeFactory::$_int;
		}

		// infer the variable type
		$node->val->symbol->declaration->infered_type = ($start_type === TypeFactory::$_int || $end_type === TypeFactory::$_int)
			? TypeFactory::$_int
			: TypeFactory::$_uint;

		$result_type = $this->infer_block($node);

		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_while_block(WhileBlock $node): ?IType
	{
		$this->infer_expression($node->condition);
		$result_type = $this->infer_block($node);

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_loop_block(LoopBlock $node): ?IType
	{
		$result_type = $this->infer_block($node);

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_try_block(TryBlock $node): ?IType
	{
		$result_type = $this->infer_block($node);

		if ($node->except) {
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

	private function infer_statement(IStatement $node): ?IType
	{
		switch ($node::KIND) {
			case NormalStatement::KIND:
				$this->infer_expression($node->expression);
				break;

			case Assignment::KIND:
			case CompoundAssignment::KIND:
				$this->check_assignment($node);
				break;

			case ArrayElementAssignment::KIND:
				$this->check_array_element_assignment($node);
				break;

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
				return $this->infer_return_statement($node);

			case ExitStatement::KIND:
				$this->check_exit_statement($node);
			case BreakStatement::KIND:
			case ContinueStatement::KIND:
				$node->condition && $this->check_condition_clause($node);
				break;

			case UnsetStatement::KIND:
				$this->check_unset_statement($node);
				break;

			case IfBlock::KIND:
				return $this->infer_if_block($node);

			case ForInBlock::KIND:
				return $this->infer_forin_block($node);

			case ForToBlock::KIND:
				return $this->infer_forto_block($node);

			case WhileBlock::KIND:
				return $this->infer_while_block($node);

			case LoopBlock::KIND:
				return $this->infer_loop_block($node);

			case TryBlock::KIND:
				return $this->infer_try_block($node);

			case SwitchBlock::KIND:
				return $this->infer_switch_block($node);

			case UseStatement::KIND:
				break;

			// case SuperVariableDeclaration::KIND:
			// 	$this->check_variable_declaration($node);
			// 	break;

			// case CoroutineBlock::KIND:
			// 	$this->check_coroutine_block($node);
			// 	break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unknow statement kind: '{$kind}'", $node);
		}

		return null;
	}

	private function expect_infered_type(BaseExpression $node, IType ...$types)
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
		$node->condition && $this->check_condition_clause($node);

		$node->belong_block->is_returned = true;
	}

	private function infer_return_statement(ReturnStatement $node)
	{
		$infered = $node->argument ? $this->infer_expression($node->argument) : null;
		$node->condition && $this->check_condition_clause($node);

		$node->belong_block->is_returned = true;

		return $infered;
	}

	private function check_exit_statement(ExitStatement $node)
	{
		$node->argument === null || $this->expect_infered_type($node->argument, TypeFactory::$_uint, TypeFactory::$_int);
		$node->condition && $this->check_condition_clause($node);

		$node->belong_block->is_returned = true;
	}

	private function check_unset_statement(UnsetStatement $node)
	{
		$argument = $node->argument;
		$this->infer_expression($argument);

		if (!$argument instanceof KeyAccessing) {
			throw $this->new_syntax_error("The unset target must be a KeyAccessing", $argument);
		}
	}

	private function check_condition_clause(PostConditionAbleStatement $node)
	{
		$this->infer_expression($node->condition);
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

	private function check_array_element_assignment(ArrayElementAssignment $node)
	{
		$master_type = $this->infer_expression($node->master);

		if (!$master_type) {
			throw $this->new_syntax_error("identifier '{$node->master->name}' not defined", $node->master);
		}

		if (!$master_type instanceof ArrayType && $master_type !== TypeFactory::$_any) {
			throw $this->new_syntax_error("Cannot assign with element accessing for type '{$master_type->name}' expression", $node->master);
		}

		if ($node->key) {
			$key_type = $this->infer_expression($node->key);
			if ($key_type !== TypeFactory::$_uint) {
				throw $this->new_syntax_error("Type for Array key expression should be int", $node);
			}
		}

		// check the value type is valid
		$infered = $this->infer_expression($node->value);
		if ($master_type !== TypeFactory::$_any && $master_type->generic_type) {
			$this->assert_type_compatible($master_type->generic_type, $infered, $node->value);
		}
	}

	private function check_assignment(IAssignment $node)
	{
		$infered = $this->infer_expression($node->value);

		if ($infered === TypeFactory::$_void) {
			throw $this->new_syntax_error("Cannot use the Void type as a value", $node->value);
		}

		$master = $node->master;

		if ($master instanceof AccessingIdentifier) {
			$master_type = $this->infer_accessing_identifier($master);
		}
		elseif ($master instanceof KeyAccessing) {
			$master_type = $this->infer_key_accessing($master); // it should be not null

			// // for generates the use-variables for lambda
			// if (isset($master->left->lambda)) {
			// 	$master->left->lambda->mutating_variable_names[] = $master->left->name;
			// }
		}
		elseif ($master instanceof SquareAccessing) {
			$master_type = $this->infer_square_accessing($master); // it should be not null
		}
		else {
			// the PlainIdentifier
			$master_type = $master->symbol->declaration->declared_type;

			// // for generates the use-variables for lambda
			// if (isset($master->lambda)) {
			// 	$master->lambda->mutating_variable_names[] = $master->name;
			// }
		}

		if (!ASTHelper::is_assignable_expr($master)) {
			if ($master instanceof KeyAccessing) {
				throw $this->new_syntax_error("Cannot change a immutable item", $master->left);
			}
			elseif ($master instanceof SquareAccessing) {
				throw $this->new_syntax_error("Cannot change a immutable item", $master);
			}
			else {
				throw $this->new_syntax_error("Cannot assign to a final(un-reassignable) item", $master);
			}
		}

		if ($master_type) {
			$this->assert_type_compatible($master_type, $infered, $node->value);
		}
		else {
			// just for the undeclared var
			$master->symbol->declaration->infered_type = $infered;
		}
	}

	private function infer_expression(BaseExpression $node): IType
	{
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
					? TypeFactory::$_pure_string
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
			// case LiteralArray::KIND:
			// 	$infered = $this->infer_array_expression($node);
			// 	break;
			// case LiteralDict::KIND:
			// 	$infered = $this->infer_dict_expression($node);
			// 	break;
			// case LiteralObject::KIND:
			// 	$infered = $this->infer_object_expression($node);
			// 	break;

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
			// case ClassKindredIdentifier::KIND:
			// 	$infered = $this->infer_classkindred_identifier($node);
			// 	break;
			case ConstantIdentifier::KIND:
				$infered = $this->infer_constant_identifier($node);
				break;
			case CallExpression::KIND:
				$infered = $this->infer_call_expression($node);
				break;
			case PipeCallExpression::KIND:
				$infered = $this->infer_pipecall_expression($node);
				break;
			case BinaryOperation::KIND:
				$infered = $this->infer_binary_operation($node);
				break;
			case PrefixOperation::KIND:
				$infered = $this->infer_prefix_operation($node);
				break;
			case Parentheses::KIND:
				$infered = $this->infer_expression($node->expression);
				break;
			case CastOperation::KIND:
				$infered = $this->infer_cast_operation($node);
				break;
			case IsOperation::KIND:
				$infered = $this->infer_is_operation($node);
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
			// case IncludeExpression::KIND:
			// 	$infered = $this->infer_include_expression($node);
			// 	break;
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

		$node->infered_type = $infered;
		return $infered;
	}

	private function infer_square_accessing(SquareAccessing $node): IType
	{
		$master_type = $this->infer_expression($node->expression);

		if ($master_type instanceof ArrayType) {
			$infered = $master_type->generic_type;
		}
		elseif ($master_type instanceof AnyType) {
			$infered = TypeFactory::$_any;
		}
		elseif ($master_type instanceof UnionType) {
			if (!$master_type->is_all_array_types()) {
				$type_name = $this->get_type_name($master_type);
				throw $this->new_syntax_error("Cannot use square accessing for type {$type_name}", $node);
			}

			$member_value_types = [];
			foreach ($master_type->get_members() as $member) {
				$member_value_types[] = $member->generic_type;
			}

			$infered = $this->reduce_expr_types(...$member_value_types);
		}
		else {
			$type_name = $this->get_type_name($master_type);
			throw $this->new_syntax_error("Cannot use square accessing for type {$type_name}", $node);
		}

		return $infered ?? TypeFactory::$_any;
	}

	private function infer_key_accessing(KeyAccessing $node): IType
	{
		$left_type = $this->infer_expression($node->left);
		$right_type = $node->right ? $this->infer_expression($node->right) : null;

		if ($left_type instanceof ArrayType) {
			if ($node->right and $right_type !== TypeFactory::$_uint) {
				$type_name = self::get_type_name($right_type);
				throw $this->new_syntax_error("Index type for Array accessing should be UInt, {$type_name} given", $node->right);
			}

			$infered = $left_type->generic_type;
		}
		elseif ($left_type instanceof DictType) {
			if ($node->right === null) {
				throw $this->new_syntax_error("Invalid accessing for Dict", $node);
			}

			if (TypeHelper::is_dict_key_type($right_type)) {
				// okay
			}
			else {
				$type_name = $this->get_type_name($right_type);
				throw $this->new_syntax_error("Key type for Dict accessing should be String/Int, {$type_name} given", $node->right);
			}

			$infered = $left_type->generic_type;
		}
		elseif ($left_type instanceof AnyType) {
			// if non key, that's Array access, else just allow Dict as the actual type
			if ($node->right and !TypeHelper::is_dict_key_type($right_type)) {
				$type_name = $this->get_type_name($right_type);
				throw $this->new_syntax_error("Key type for Dict accessing should be String/Int, {$type_name} given", $node->right);
			}
		}
		elseif ($left_type instanceof StringType) {
			if ($right_type !== TypeFactory::$_uint && $right_type !== TypeFactory::$_int) {
				throw $this->new_syntax_error("Index type for String should be Int/UInt, '{$right_type->name}' given", $node);
			}

			$infered = TypeFactory::$_string;
		}
		elseif ($left_type instanceof UnionType) {
			if ($left_type->is_all_array_types()) {
				if ($right_type !== TypeFactory::$_uint) {
					$type_name = self::get_type_name($right_type);
					throw $this->new_syntax_error("Index type for Array accessing should be UInt, {$type_name} given", $node->right);
				}
			}
			elseif ($left_type->is_all_dict_types()) {
				if (!TypeHelper::is_dict_key_type($right_type)) {
					throw $this->new_syntax_error("Key type for Dict accessing should be String/Int, '{$right_type->name}' given", $node->right);
				}
			}
			else {
				$type_name = $this->get_type_name($left_type);
				throw $this->new_syntax_error("Cannot use key accessing for type {$type_name}", $node);
			}

			$member_value_types = [];
			foreach ($left_type->get_members() as $member) {
				$member_value_types[] = $member->generic_type;
			}

			$infered = $this->reduce_expr_types(...$member_value_types);
		}
		else {
			$type_name = $this->get_type_name($left_type);
			throw $this->new_syntax_error("Cannot use key accessing for type {$type_name}", $node);
		}

		return $infered ?? TypeFactory::$_any;
	}

	private function infer_binary_operation(BinaryOperation $node): IType
	{
		$operator = $node->operator;
		$left_type = $this->infer_expression($node->left);
		$right_type = $this->infer_expression($node->right);

		if (OperatorFactory::is_number_operator($operator)) {
			$this->assert_math_operable($left_type, $node->left);
			$this->assert_math_operable($right_type, $node->right);

			if ($operator->is(OPID::DIVISION) || $left_type === TypeFactory::$_float || $right_type === TypeFactory::$_float) {
				$infered = TypeFactory::$_float;
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
				if ($node->left instanceof BinaryOperation and $node->left->type_assertion) {
					$node->type_assertion = $node->left->type_assertion;
				}
				elseif ($node->right instanceof BinaryOperation and $node->right->type_assertion) {
					$node->type_assertion = $node->right->type_assertion;
				}
			}
		}
		elseif ($operator->is(OPID::CONCAT)) {
			// String or Array
			if ($left_type instanceof ArrayType) {
				$this->assert_type_compatible($left_type, $right_type, $node->right, 'concat');
				$node->operator = OperatorFactory::$array_concat; // replace to array concat
				$infered = $left_type;
			}
			elseif (!TypeHelper::is_stringable_type($left_type)) {
				$type_name = $this->get_type_name($left_type);
				throw $this->new_syntax_error("'concat' operation cannot use for '$type_name' type targets", $node->left);
			}
			else {
				// string
				$is_pure = $left_type instanceof IPureType && $right_type instanceof IPureType;
				$infered = $is_pure ? TypeFactory::$_pure_string : TypeFactory::$_string;
			}
		}
		elseif ($operator->is(OPID::MERGE)) {
			// Array or Dict
			if (!$left_type instanceof DictType) {
				throw $this->new_syntax_error("'merge' operation just support Dict type targets", $node);
			}

			$this->assert_type_compatible($left_type, $right_type, $node->right, 'merge');
			$infered = $left_type;
		}
		elseif (OperatorFactory::is_bitwise_operator($operator)) {
			$infered = $this->reduce_expr_types($left_type, $right_type);
		}
		else {
			$sign = $node->operator->get_debug_sign();
			throw $this->new_syntax_error("Unknow operator: {$sign}", $node);
		}

		$node->infered_type = $infered;
		return $infered;
	}

	private function infer_cast_operation(CastOperation $node): IType
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
		elseif ($operator->is(OPID::REFERENCE)) {
			$infered = $expr_type;
		}
		elseif ($operator->is(OPID::BITWISE_NOT)) {
			$this->assert_bitwise_operable($expr_type, $node->expression);
			$infered = $expr_type === TypeFactory::$_uint || $expr_type === TypeFactory::$_int || $expr_type === TypeFactory::$_float
				? TypeFactory::$_int
				: $expr_type;
		}
		elseif ($operator->is(OPID::CLONE)) {
			$infered = $expr_type;
		}
		else {
			$sign = $operator->get_debug_sign();
			throw $this->new_syntax_error("Unknow operator: {$sign}", $node);
		}

		return $infered;
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
		if (!TypeHelper::is_number_type($type, $node)) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Math operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function assert_bool_operable(IType $type, BaseExpression $node)
	{
		if (!$type instanceof BoolType && !$type instanceof UIntType && !$type instanceof IntType) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Bool operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function infer_none_coalescing_expression(NoneCoalescingOperation $node): IType
	{
		$types = [];
		foreach ($node->items as $item) {
			$infered = $this->infer_expression($item);
			$types[] = $infered;
		}

		$reduced_type = $this->reduce_expr_types(...$types);

		// none has been coalesced
		if (!$infered instanceof NoneType) {
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

		return $this->reduce_expr_types(...$infereds);
	}

	private function infer_ternary_expression_with_asserttion(TernaryExpression $node, IType $condition_type): array
	{
		$type_assertion = $node->condition->type_assertion;

		$left_declaration = $type_assertion->left->symbol->declaration;
		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = $left_declaration->infered_type;
		$asserting_type = $type_assertion->right;

		if ($type_assertion->not) {
			if ($left_original_type instanceof UnionType) {
				$asserted_then_type = $left_original_type->get_members_type_except($asserting_type);
			}

			$asserted_else_type = $asserting_type;
		}
		else {
			if ($left_original_type instanceof UnionType) {
				$asserted_else_type = $left_original_type->get_members_type_except($asserting_type);
			}

			$asserted_then_type = $asserting_type;
		}

		if ($node->then === null) {
			$then_type = $condition_type;
		}
		else {
			// it would infer with the asserted then type
			$asserted_then_type and $left_declaration->infered_type = $asserted_then_type;
			$then_type = $this->infer_expression($node->then);
		}

		// it would infer with the asserted else type
		$left_declaration->infered_type = $asserted_else_type ?? $left_original_type;
		$else_type = $this->infer_expression($node->else);

		// reset to original type
		$left_declaration->infered_type = $left_original_type;

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

		$item_type = $this->reduce_expr_types(...$infered_item_types);

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

		$generic_type = $this->reduce_expr_types(...$infered_value_types);

		return TypeFactory::create_dict_type($generic_type);
	}

	private function infer_dict_member(DictMember $item)
	{
		$key_type = $this->infer_expression($item->key);
		if (!TypeHelper::is_dict_key_type($key_type)) {
			$type_name = $this->get_type_name($key_type);
			throw $this->new_syntax_error("Key type for Dict should be String/Int, {$type_name} given", $item->key);
		}

		return $this->infer_expression($item->value);
	}

	private function infer_object_expression(ObjectExpression $node): IType
	{
		$this->check_local_classkindred_declaration($node->class_declaration);

		$object_type = clone TypeFactory::$_object;
		$object_type->symbol = $node->class_declaration->symbol;

		$node->infered_type = $object_type;

		return $object_type;
	}

	private function infer_callback_argument(CallbackArgument $node): IType
	{
		$value_expr = $node->value;
		if ($value_expr instanceof AnonymousFunction) {
			$this->infer_anonymous_function($value_expr);
			$declar = $value_expr;
		}
		else {
			$this->infer_expression($value_expr);
			$declar = $value_expr->symbol->declaration;
		}

		$infered = $declar->get_type();
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
			$infered = $this->infer_plain_identifier($type);
			if ($type->symbol and !$type->symbol->declaration instanceof ClassKindredDeclaration) {
				$declare_name = $this->get_declaration_name($type->symbol->declaration);
				throw $this->new_syntax_error("Cannot use '$declare_name' as a Type", $type);
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

	// private function infer_classkindred_identifier(ClassKindredIdentifier $node): ?IType
	// {
	// 	$declaration = $this->get_classkindred_declaration($node);
	// 	return $declaration ? $declaration->declared_type : null;
	// }

	private function infer_constant_identifier(ConstantIdentifier $node): IType
	{
		if (!$node->symbol) {
			$this->attach_symbol($node);
		}

		return $node->symbol->declaration->get_type();
	}

	private function infer_yield_expression(YieldExpression $node): IType
	{
		$this->infer_expression($node->argument);
		return TypeFactory::$_any;
	}

	// private function infer_include_expression(IncludeExpression $node): ?IType
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

	protected function require_program_declaration(string $name, Node $ref_node)
	{
		$program = $this->unit->programs[$name] ?? null;
		if (!$program) {
			throw $this->new_syntax_error("'{$ref_node->target}' not found", $ref_node);
		}

		// $program->unit->get_checker()->check_program($program);
		self::get_checker($program)->check_program($program);

		return $program;
	}

	private function infer_pipecall_expression(PipeCallExpression $node): IType
	{
		return $this->infer_basecall_expression($node);
	}

	private function infer_call_expression(CallExpression $node): IType
	{
		return $this->infer_basecall_expression($node);
	}

	private function infer_basecall_expression(BaseCallExpression $node): IType
	{
		$callee_declar = $this->require_callee_declaration($node->callee);

		// if $callee_declar is AnyType, do not has the type property
		$callee_type = $callee_declar->get_type();

		// cache for render
		$node->infered_callee_declaration = $callee_declar;

		// if is a variable, use it's type declaration
		if ($callee_declar instanceof IVariableDeclaration) {
			// if ($callee_type instanceof MetaType) {
			// 	// if is MetaType, use the Declaration of it's value type
			// 	$callee_declar = $callee_type->generic_type->symbol->declaration;
			// }

			$callee_declar = $callee_declar->value;
		}

		if ($callee_declar === TypeFactory::$_callable) {
			// the Any-Callable type do not need to match parameters
			foreach ($node->arguments as $argument) {
				$this->infer_expression($argument);
			}
		}
		elseif ($callee_declar instanceof ICallableDeclaration) {
			// check arguments
			$this->check_call_arguments($node, $callee_declar);
		}
		else {
			throw $this->new_syntax_error("Callee not a valid callable declaration", $node->callee);
		}

		if ($callee_declar instanceof ClassKindredDeclaration) {
			if (!$callee_declar instanceof ClassDeclaration) {
				throw $this->new_syntax_error("Invalid call for: '{$callee_declar->name}'", $node);
			}

			$infered = $node->callee;
			if ($infered->symbol->declaration instanceof IVariableDeclaration) {
				$infered = $infered->symbol->declaration->get_type()->generic_type;
			}
		}
		else {
			$infered = $callee_type;

			// if ($infered->symbol and $infered->symbol->declaration instanceof IVariableDeclaration) {
			// 	$infered = $infered->symbol->declaration->get_type();
			// 	if ($infered instanceof MetaType) {
			// 		$infered = $infered->generic_type;
			// 	}
			// }

			// // the actual called return type of MetaType for Variable is it's value type
			// if ($infered instanceof MetaType and $callee_declar instanceof VariableDeclaration) {
			// 	$infered = $infered->generic_type;
			// }
		}

		if ($infered === null) {
			throw $this->new_syntax_error("Unable to infer type, perhaps a loop call has occurred", $node->callee);
		}

		return $infered;
	}

	private function check_call_arguments(BaseCallExpression $node, ICallableDeclaration $callee_declar)
	{
		$arguments = $node->arguments;

		// if is a class, use it's construct declaration
		if ($callee_declar instanceof ClassDeclaration) {
			$callee_declar = $this->require_construct_declaration_for_class($callee_declar, $node);
			if ($callee_declar === null) {
				if ($arguments) {
					throw $this->new_syntax_error("Cannot use arguments to create a non-construct class instance", $argument);
				}

				return;
			}
		}

		$parameters = $callee_declar->parameters;

		// the -> style callbacks for normal call
		if (isset($node->callbacks)) {
			$this->merge_callbacks_to_arguments($arguments, $node->callbacks, $parameters);
		}

		$has_named_arguments = false;

		$normalizeds = [];
		foreach ($arguments as $key => $argument) {
			if (is_numeric($key)) {
				$parameter = $parameters[$key] ?? null;
				if (!$parameter) {
					// dump($callee_declar);
					$declar_name = $this->get_declaration_name($callee_declar);
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
			$infered = $this->infer_expression($argument);
			if (!$param_type->is_accept_type($infered)) {
				$callee_name = self::get_declaration_name($callee_declar);
				$expected_type_name = self::get_type_name($param_type);
				$infered_name = self::get_type_name($infered);

				if (!is_int($key)) {
					$key = "'$key'";
				}

				throw $this->new_syntax_error("Type of argument $key does not matched the parameter, expected {$expected_type_name}, {$infered_name} given", $argument);
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
		foreach ($parameters as $idx => $parameter) {
			if ($parameter->value === null && !isset($normalizeds[$idx])) {
				$callee_name = self::get_declaration_name($callee_declar);
				$param_name = $callee_declar->parameters[$idx]->name;
				throw $this->new_syntax_error("Required argument '$param_name' to call '{$callee_name}'", $node);
			}
		}

		// fill the default value when needed
		if ($has_named_arguments) {
			$last_idx = array_key_last($normalizeds);

			$i = 0;
			foreach ($parameters as $parameter) {
				if ($i <= $last_idx && !isset($normalizeds[$i])) {
					$normalizeds[$i] = $parameter->value;
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

	private function require_construct_declaration_for_class(ClassDeclaration $class, BaseCallExpression $call)
	{
		$symbol = $this->find_member_symbol_in_class_declaration($class, _CONSTRUCT);

		if (!$symbol) {
			if ($call->arguments || $call->callbacks) {
				throw $this->new_syntax_error("'construct' in '{$class->name}' not found", $call);
			}

			return; // no any to check
		}

		return $symbol->declaration;
	}

	private function is_strict_compatible_types(IType $left, IType $right)
	{
		return $left === $right
			|| $left->symbol === $right->symbol
			|| ($left === TypeFactory::$_int && $right === TypeFactory::$_uint)
		;
	}

	private function assert_type_compatible(IType $left, IType $right, Node $value_node, string $kind = 'assign')
	{
		if (!$this->is_type_compatible($left, $right, $value_node)) {
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
		if (!$node->symbol) {
			$this->attach_symbol($node);
		}

		$declar = $node->symbol->declaration;

		if ($declar instanceof VariableDeclaration || $declar instanceof ParameterDeclaration || $declar instanceof ConstantDeclaration || $declar instanceof ClassKindredDeclaration) {
			$type = $declar->get_type();
			if (!$type) {
				throw $this->new_syntax_error("Declaration of '{$node->name}' not found", $node);
			}
		}
		elseif ($declar instanceof CallableType) {
			$type = $declar;
		}
		elseif ($declar instanceof ICallableDeclaration) {
			$type = TypeFactory::create_callable_type($declar->get_type(), $declar->parameters);
		}
		// elseif ($declar instanceof NamespaceDeclaration) {
		// 	$type = TypeFactory::$_namespace;
		// }
		else {
			throw $this->new_syntax_error('Undexpected declaration for identifier', $node);
		}

		return $type;
	}

	private function infer_accessing_identifier(AccessingIdentifier $node): IType
	{
		$member = $this->require_accessing_identifier_declaration($node);
		switch ($member::KIND) {
			case MethodDeclaration::KIND:
			case FunctionDeclaration::KIND:
				$infered = TypeFactory::create_callable_type($declar->get_type(), $declar->parameters);
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
		$this->check_interpolated_items($node->items);

		$is_pure = true;
		foreach ($node->items as $item) {
			if ($item instanceof StringInterpolation) {
				if (!$item->infered_type instanceof IPureType) {
					$is_pure = false;
					break;
				}
			}
			elseif (!TeaHelper::is_pure_string($item)) {
				$is_pure = false;
				break;
			}
		}

		return $is_pure ? TypeFactory::$_pure_string : TypeFactory::$_string;
	}

	private function infer_escaped_interpolated_string(EscapedInterpolatedString $node): IType
	{
		$this->check_interpolated_items($node->items);
		return TypeFactory::$_string;
	}

	private function check_interpolated_items(array $items)
	{
		foreach ($items as $item) {
			if ($item instanceof StringInterpolation) {
				$item->infered_type = $this->infer_expression($item->content);
			}
		}
	}

	private function infer_variable_identifier(VariableIdentifier $node): IType
	{
		return $this->attach_symbol($node)->declaration->get_type();
	}

	private function infer_xtag(XTag $node)
	{
		$fixed_attr_map = $node->fixed_attributes;
		$dyn_expr = $node->dynamic_attributes;
		$children = $node->children ?? [];

		foreach ($fixed_attr_map as $item) {
			if ($item instanceof XTagAttrInterpolation) {
				$item->infered_type = $this->infer_expression($item->content);
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
				$infered = $this->infer_expression($item->content);
				if (!TypeHelper::is_xtag_child_type($infered)) {
					$type_name = self::get_type_name($infered);
					throw $this->new_syntax_error("Expect String/XView/XView.Array type value, {$type_name} given", $item);
				}

				$item->infered_type = $infered;
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

	protected function attach_symbol(PlainIdentifier $identifier)
	{
		$symbol = $this->find_plain_symbol_and_check_declaration($identifier);
		if ($symbol === null) {
			throw $this->new_syntax_error("Symbol of '{$identifier->name}' not found", $identifier);
		}

		$identifier->symbol = $symbol;
		return $symbol;
	}

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

	protected function find_plain_symbol_and_check_declaration(PlainIdentifier $identifier)
	{
		$symbol = $this->find_symbol_for_plain_identifier($identifier);

		if ($symbol === null && $identifier->name === _SUPER) {
			$symbol = $this->get_symbol_for_super_identifier($identifier);
		}

		$symbol and $this->check_declaration_for_symbol($symbol);

		// find in package level symbols
		return $symbol;
	}

	private function get_symbol_for_super_identifier(PlainIdentifier $identifier)
	{
		if ($this->current_function->belong_block->inherits === null) {
			// dump($this->current_function);
			throw $this->new_syntax_error("There are not inherits a class/interface for 'super' reference", $identifier);
		}

		$inherits_class = $this->current_function->belong_block->inherits->symbol->declaration;
		if ($this->current_function->is_static) {
			$symbol = $inherits_class->this_class_symbol;
		}
		else {
			$symbol = $inherits_class->this_object_symbol;
		}

		return $symbol;
	}

	private function check_declaration_for_symbol(Symbol $symbol)
	{
		if ($symbol->declaration instanceof UseDeclaration) {
			$symbol->declaration = $this->get_source_declaration_for_use($symbol->declaration);
		}
		elseif (!$symbol->declaration->is_checked) {
			$this->check_declaration($symbol->declaration);
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
		$ns_decl = $this->find_namespace_declaration_in_unit($unit, $ns);
		return $ns_decl->symbols[$name] ?? null;
	}

	private function find_namespace_declaration_in_unit(Unit $unit, NamespaceIdentifier $ns)
	{
		$namepath = $ns->get_namepath();
		$ns_name = array_shift($namepath);

		$ns_decl = $unit->namespaces[$ns_name] ?? null;
		if ($ns_decl !== null) {
			foreach ($namepath as $ns_name) {
				$ns_decl = $ns_decl->namespaces[$ns_name] ?? null;
				if ($ns_decl === null) {
					break;
				}
			}
		}

		return $ns_decl;
	}

	private function require_callee_declaration(BaseExpression $node): IDeclaration
	{
		if ($node instanceof AccessingIdentifier) {
			$declar = $this->require_accessing_identifier_declaration($node);
		}
		elseif ($node instanceof PlainIdentifier) {
			$declar = $this->require_callable_declaration($node);
		}
		elseif ($node instanceof ClassKindredIdentifier) {
			$declar = $this->get_classkindred_declaration($node, true);
		}
		else {
			$infered = $this->infer_expression($node);
			if (!$infered instanceof ICallableDeclaration) {
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unknow callee kind: '$kind'", $node);
			}

			$declar = $infered;
		}

		return $declar;
	}

	private function require_callable_declaration(BaseExpression $node)
	{
		$node->symbol || $this->attach_symbol($node);

		$declar = $node->symbol->declaration;
		$return_type = $declar->get_type();
		if ($declar instanceof ICallableDeclaration) {
			$return_type === null && $this->check_callable_declaration($declar);
		}
		elseif ($return_type instanceof ICallableDeclaration) {
			$declar = $return_type;
		}
		elseif ($return_type === TypeFactory::$_callable) {
			// for Callable type parameters
		}
		elseif ($return_type instanceof MetaType and $return_type->generic_type instanceof ClassKindredIdentifier) {
			$declar = $this->get_classkindred_declaration($return_type->generic_type, true);
		}
		else {
			throw $this->new_syntax_error("Invalid callable expression", $node);
		}

		return $declar;
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
		$master = $node->master;
		$master_type = $this->infer_expression($master);

		if ($master_type instanceof BaseType) {
			$this->attach_symbol_for_basetype_accessing_identifier($node, $master_type);
		}
		elseif ($master_type instanceof Identifiable) {
			// the master would be an object expression, or variable of MetaType
			$master_declar = $master_type->symbol->declaration;
			if ($master_declar instanceof VariableDeclaration) {
				$master_declar = $master_declar->get_type()->generic_type->symbol->declaration;
			}

			$node->symbol = $this->require_class_member_symbol($master_declar, $node);

			if (!$this->is_instance_accessable($master, $node->symbol->declaration)) {
				throw $this->new_syntax_error("Cannot access the private/protected members", $node);
			}
		}
		else {
			$type_name = $this->get_type_name($master_type);
			throw $this->new_syntax_error("Invalid accessable type '$type_name'", $master);
		}

		return $node->symbol->declaration;
	}

	private function attach_symbol_for_basetype_accessing_identifier(AccessingIdentifier $node, BaseType $master_type)
	{
		// if ($master_type === TypeFactory::$_any) {
		// 	// let member type to Any on master is Any
		// 	$this->create_any_symbol_for_accessing_identifier($node);
		// }
		// elseif ($master_type === TypeFactory::$_namespace) {
		// 	$this->attach_namespace_member_symbol($node->master->symbol->declaration, $node);
		// }
		if ($master_type instanceof MetaType) {
			$this->attach_symbol_for_metatype_accessing_identifier($node, $master_type);
		}
		elseif ($master_type instanceof UnionType) {
			throw $this->new_syntax_error("Cannot accessing the 'UnionType' targets", $node);
		}
		else {
			$classkindred = $master_type->symbol->declaration;
			$symbol = $this->find_member_symbol_in_class_declaration($classkindred, $node->name);
			if ($symbol === null) {
				// if ($master_type === TypeFactory::$_any) {
				// 	// let member type to Any on master is Any when member not defined
				// 	$this->create_any_symbol_for_accessing_identifier($node);
				// }
				// else {
					throw $this->new_syntax_error("Member '{$node->name}' not found in '{$classkindred->name}'", $node);
				// }
			}
			else {
				$node->symbol = $symbol;
			}
		}
	}

	private function attach_symbol_for_metatype_accessing_identifier(Node $node, MetaType $master_type) {
		$declaration = $master_type->generic_type->symbol->declaration;
		// if (!$declaration instanceof ClassDeclaration) {
		// 	$declaration = $declaration->get_type()->generic_type->symbol->declaration;
		// }

		// find static member for classes
		$symbol = $this->find_member_symbol_in_class_declaration($declaration, $node->name);
		if ($symbol === null) {
			throw $this->new_syntax_error("Member '{$node->name}' not found in '{$declaration->name}'", $node);
		}

		// check static member

		$node->symbol = $symbol;
		$node_declaration = $symbol->declaration;
		if (!$node_declaration->is_static) {
			throw $this->new_syntax_error("Invalid to accessing a non-static member", $node);
		}

		if (!$this->is_static_accessable($node->master, $node_declaration)) {
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

	private function find_member_symbol_in_class_declaration(ClassKindredDeclaration $classkindred, string $member_name): ?Symbol
	{
		// 当作为外部调用时，应命中这里的逻辑
		$declaration = $classkindred->aggregated_members[$member_name] ?? null;
		if ($declaration) {
			return $declaration->symbol;
		}

		// 当目标为正在检查的类的成员时，需要走以下逻辑

		// find in self
		$declaration = $classkindred->members[$member_name] ?? null;
		if ($declaration) {
			if (!$declaration->is_checked) {
				// switch to target program
				$temp_program = $this->program;
				$this->program = $classkindred->program;

				$this->check_class_member_declaration($declaration);

				// switch back
				$this->program = $temp_program;
			}

			return $declaration->symbol;
		}

		// find in extends class
		$inherits = $classkindred->inherits;
		if ($inherits and $inherits->symbol) {
			$member_symbol = $this->find_member_symbol_in_class_declaration($inherits->symbol->declaration, $member_name);
			if ($member_symbol) {
				return $member_symbol;
			}
		}

		// find in implements interfaces
		if ($classkindred->bases) {
			foreach ($classkindred->bases as $interface) {
				$member_symbol = $this->find_member_symbol_in_interface_identifier($interface, $member_name);
				if ($member_symbol) {
					return $member_symbol;
				}
			}
		}

		return null;
	}

	private function find_member_symbol_in_interface_identifier(ClassKindredIdentifier $interface, string $member_name)
	{
		if ($interface->symbol) {
			$interface_declar = $interface->symbol->declaration;
		}
		else {
			$interface_declar = $this->get_classkindred_declaration($interface);
			if ($interface_declar === null) {
				return null;
			}
		}

		return $this->find_member_symbol_in_class_declaration($interface_declar, $member_name);
	}

	private function require_class_member_symbol(ClassKindredDeclaration $classkindred, Identifiable $node): Symbol
	{
		$symbol = $this->find_member_symbol_in_class_declaration($classkindred, $node->name);
		if (!$symbol) {
			throw $this->new_syntax_error("Member '{$node->name}' not found in '{$classkindred->name}'", $node);
		}

		return $symbol;
	}

	// private function attach_namespace_member_symbol(NamespaceDeclaration $ns_declaration, AccessingIdentifier $node)
	// {
	// 	$node->symbol = $ns_declaration->symbols[$node->name] ?? null;
	// 	if (!$node->symbol) {
	// 		throw $this->new_syntax_error("Symbol '{$node->name}' not found in '{$ns_declaration->name}'", $node);
	// 	}

	// 	return $node->symbol;
	// }

	// includes builtin types, and classes, and namespace
	// private function require_object_declaration(BaseExpression $node): ClassKindredDeclaration
	// {
	// 	$infered = $this->infer_expression($node);
	// 	if (!$infered instanceof IType) {
	// 		throw new UnexpectNode($node);
	// 	}

	// 	if ($infered === TypeFactory::$_namespace) {
	// 		return $node->declaration;
	// 	}

	// 	return $infered->symbol->declaration;
	// }

	private function get_classkindred_declaration(ClassKindredIdentifier $identifier, bool $required = false)
	{
		$symbol = $identifier->symbol;
		if ($symbol === null) {
			$symbol = $this->find_symbol_for_plain_identifier($identifier);
			if ($symbol === null) {
				if (!$required and $this->is_weakly_typed_system) {
					return null;
				}

				$name = $this->get_type_name($identifier);
				throw $this->new_syntax_error("Symbol of '{$name}' not found when find classkindred identifier", $identifier);
			}

			$this->attach_symbol_for_classkindred_identifier($identifier, $symbol);
		}

		$declaration = $symbol->declaration;
		$this->check_depends_classkindred_declaration($declaration);

		return $declaration;
	}

	private function check_depends_classkindred_declaration(ClassKindredDeclaration $declaration)
	{
		if ($declaration->is_checked) {
			return;
		}

		$temp_program = $this->program;
		$this->program = $declaration->program;
		$this->check_local_classkindred_declaration($declaration);
		$this->program = $temp_program;
	}

	private function attach_symbol_for_classkindred_identifier(ClassKindredIdentifier $identifier, Symbol $symbol)
	{
		if ($symbol->declaration instanceof UseDeclaration) {
			$symbol->declaration = $this->get_source_declaration_for_use($symbol->declaration);
		}
		elseif (!$symbol->declaration instanceof ClassKindredDeclaration) {
			throw $this->new_syntax_error("Declaration of '{$identifier->name}' not a classkindred declaration", $identifier);
		}

		$identifier->symbol = $symbol;
	}

	// private function require_namespace_declaration_in_unit(NamespaceIdentifier $identifier)
	// {
	// 	$ns_decl = $this->find_namespace_declaration_in_unit($this->unit, $identifier)
	// 		?? $this->find_namespace_declaration_in_unit($this->builtin_unit, $identifier);
	// 	if ($ns_decl === null) {
	// 		throw $this->new_syntax_error("Namespace '{$identifier->uri}' not found in package '{$this->unit->uri}'", $identifier);
	// 	}

	// 	return $ns_decl;
	// }

	private function get_source_declaration_for_use(UseDeclaration $use): ?IRootDeclaration
	{
		if ($use->source_declaration === null) {
			$unit = $this->get_uses_unit_declaration($use->ns);
			if ($unit === null) {
				throw $this->new_syntax_error("Unit '{$use->ns->uri}' not found", $use->ns);
			}

			$this->attach_source_declaration_for_use($use, $unit);
		}

		return $use->source_declaration;
	}

	protected function attach_source_declaration_for_use(UseDeclaration $use, Unit $unit)
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
			if (!$target_declaration->is_checked) {
				// $target_declaration->program->unit->get_checker()->check_declaration($target_declaration);
				self::get_checker($target_declaration->program)->check_declaration($target_declaration);
			}
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

	private function reduce_expr_types(IType ...$types): IType
	{
		return $this->reduce_types($types);
	}

	private function reduce_types(array $types): ?IType
	{
		$count = count($types);

		$nullable = false;
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

			$place = "Near $place";
		}

		$message = "Syntax check error:\n{$place}\n{$message}";
		DEBUG && $message .= "\n\nTraces:\n" . get_traces();

		return new Exception($message);
	}

	static function get_declaration_name(IDeclaration $declaration)
	{
		if (isset($declaration->belong_block) && $declaration->belong_block instanceof ClassDeclaration) {
			return "{$declaration->belong_block->name}.{$declaration->name}";
		}

		return $declaration->name;
	}

	static function get_type_name(IType $type)
	{
		if ($type instanceof IterableType) {
			if ($type->generic_type === null) {
				$generic_type_name = _ANY;
			}
			else {
				$generic_type_name = self::get_type_name($type->generic_type);
			}

			$name = "{$generic_type_name}.{$type->name}";
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

		if ($type->has_null) {
			$name .= ' assigned none';
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
