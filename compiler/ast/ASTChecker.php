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

	public function __construct(Unit $unit)
	{
		$this->unit = $unit;
	}

	public function collect_program_uses(Program $program)
	{
		$this->program = $program;

		foreach ($program->declarations as $node) {
			$this->collect_declaration_uses($node);
		}

		$program->main_function && $this->collect_declaration_uses($program->main_function);
	}

	private function collect_declaration_uses(IDeclaration $declaration)
	{
		foreach ($declaration->defer_check_identifiers as $identifier) {
			$symbol = $this->find_symbol_in_program($this->program, $identifier->name);
			if ($symbol === null) {
				throw $this->new_syntax_error("Symbol of '{$identifier->name}' not found.", $identifier);
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

		if ($program->main_function) {
			$this->check_declaration($program->main_function);
		}
	}

	private function check_use_target(UseDeclaration $node)
	{
		$this->attach_source_declaration_for_use($node);
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

			case ClassDeclaration::KIND:
				$this->check_classkindred_declaration($node);
				break;

			case InterfaceDeclaration::KIND:
				$this->check_classkindred_declaration($node);
				break;

			case ConstantDeclaration::KIND:
				$this->check_constant_declaration($node);
				break;

			case ExpectDeclaration::KIND:
				$this->check_expect_declaration($node);
				break;

			case SuperVariableDeclaration::KIND:
				$this->check_variable_declaration($node);
				break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unexpect declaration kind: '{$kind}'.", $node);
		}

		$this->program = $temp_program;
	}

	private function check_class_member_declaration(IClassMemberDeclaration $node)
	{
		switch ($node::KIND) {
			case MaskedDeclaration::KIND:
				$this->check_masked_declaration($node);
				break;

			case FunctionDeclaration::KIND:
				$this->check_function_declaration($node);
				break;

			case PropertyDeclaration::KIND:
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

	private function check_constant_declaration(IConstantDeclaration $node)
	{
		$node->is_checked = true;

		$value = $node->value;

		// no value, it should be in declare mode
		if ($value === null) {
			if ($node->type) {
				$this->check_type($node->type, $node);
			}
			else {
				throw $this->new_syntax_error("The type for declaration of constant '{$node->name}' required.", $node);
			}

			return;
		}

		// has value

		$infered_type = $this->infer_expression($value);
		if (!$infered_type) {
			throw $this->new_syntax_error("The type for declaration of constant '{$node->name}' required.", $node);
		}

		if (!$this->check_is_constant_expression($value)) {
			throw $this->new_syntax_error("Invalid value expression for constant declaration.", $value);
		}

		if ($value instanceof BinaryOperation) {
			if ($value->operator === OperatorFactory::$_concat) {
				$left_type = $this->infer_expression($value->left);
				if ($left_type instanceof ArrayType) {
					throw $this->new_syntax_error("Array concat operation cannot use for constant value.", $value);
				}
			}
			// elseif ($value->operator === OperatorFactory::$_merge) {
			// 	throw $this->new_syntax_error("Array/Dict merge operation cannot use for constant value.", $value);
			// }
		}

		if ($node->type) {
			$this->check_type($node->type, $node);
			$infered_type && $this->assert_type_compatible($node->type, $infered_type, $node->value);
		}
		else {
			$node->type = $infered_type;
		}
	}

	private function check_is_constant_expression(BaseExpression $node): bool
	{
		if ($node instanceof ILiteral || $node instanceof ConstantIdentifier) {
			$is_constant = true;
		}
		elseif ($node instanceof Identifiable) {
			$is_constant = $node->symbol->declaration instanceof ConstantDeclaration;
		}
		elseif ($node instanceof BinaryOperation) {
			$is_constant = $this->check_is_constant_expression($node->left) && $this->check_is_constant_expression($node->right);
		}
		elseif ($node instanceof PrefixOperation) {
			$is_constant = $this->check_is_constant_expression($expr->expression);
		}
		else {
			$is_constant = false;
		}

		return $is_constant;
	}

	private function check_variable_declaration(BaseVariableDeclaration $node)
	{
		$node->is_checked = true;

		$infered_type = $node->value ? $this->infer_expression($node->value) : null;

		$this->set_variable_similar_declaration_type($node, $infered_type);
	}

	private function set_variable_similar_declaration_type(IDeclaration $node, ?IType $infered_type)
	{
		if ($node->type) {
			$this->check_type($node->type, $node);
			$infered_type && $this->assert_type_compatible($node->type, $infered_type, $node->value);
		}
		elseif ($infered_type === TypeFactory::$_uint && $node->value instanceof IntegerLiteral) {
			// set infered type to Int when value is Integer literal
			$node->type = TypeFactory::$_int;
		}
		elseif ($infered_type === null || $infered_type === TypeFactory::$_none) {
			$node->type = TypeFactory::$_any;
		}
		else {
			$node->type = $infered_type;
		}
	}

	private function check_parameters_for_callable_declaration(ICallableDeclaration $callable)
	{
		$this->check_parameters_for_node($callable);
	}

	private function check_parameters_for_node($node)
	{
		foreach ($node->parameters as $parameter) {
			$this->check_variable_declaration($parameter);
		}
	}

	// private function check_callback_protocol(CallbackProtocol $node)
	// {
	// 	$node->is_checked = true;

	// 	if ($node->type) {
	// 		$this->check_type($node->type);
	// 	}
	// 	else {
	// 		$node->type = TypeFactory::$_void;
	// 	}

	// 	$this->check_parameters_for_callable_declaration($node);
	// }

	private function check_masked_declaration(MaskedDeclaration $node)
	{
		$node->parameters && $this->check_parameters_for_callable_declaration($node);
		$masked = $node->body;

		// maybe need render, so check first
		$temp_block = $this->block;
		$this->block = $node;
		$infered_type = $this->infer_expression($masked);
		$this->block = $temp_block;

		if (!$node->type) {
			$node->type = $infered_type;
		}

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

					throw $this->new_syntax_error("Identifier '{$item->name}' not defined in MaskedDeclaration.", $item);
				}

				// the argument from masking call
	 			$node->arguments_map[$dest_idx] = $parameters_map[$item->name];
	 			continue;
			}

			// the literal value for argument
			if ($item instanceof ILiteral || ($item instanceof PrefixOperation && $item->expression instanceof ILiteral)) {
				$node->arguments_map[$dest_idx] = $item;
				continue;
			}
			else {
				throw $this->new_syntax_error("Unexpect expression in MaskedDeclaration.", $item);
			}
		}
	}

	private function check_expect_declaration(ExpectDeclaration $node)
	{
		$this->is_checked = true;
		$this->check_parameters_for_node($node);
	}

	private function infer_lambda_expression(LambdaExpression $node)
	{
		// check for use variables
		foreach ($node->defer_check_identifiers as $identifier) {
			if (!$identifier->symbol) {
				$this->infer_plain_identifier($identifier);
			}

			if ($identifier->symbol->declaration instanceof IVariableDeclaration) {
				if ($identifier->name === _THIS || $identifier->name === _SUPER) {
					throw $this->new_syntax_error("'{$identifier->name}' cannot use in lambda functions.", $node);
				}

				// for lambda use in php
				$node->use_variables[$identifier->name] = $identifier;
			}
		}

		$this->check_parameters_for_callable_declaration($node);
		$this->check_function_body($node);

		return TypeFactory::create_callable_type($node->type, $node->parameters);
	}

	private function check_function_body(IScopeBlock $node)
	{
		if (is_array($node->body)) {
			$infered_type = $this->infer_block($node);
		}
		else {
			$infered_type = $this->infer_single_expression_block($node);
		}

		$declared_type = $node->type;
		if ($declared_type) {
			$this->check_type($declared_type, $node);
			if ($infered_type !== null) {
				if (!$declared_type->is_accept_type($infered_type)) {
					throw $this->new_syntax_error("Function '{$node->name}' returns type is '{$infered_type->name}', do not compatible with the declared '{$declared_type->name}'.", $node);
				}
			}
			elseif ($declared_type instanceof ArrayType && $declared_type->is_collect_mode) {
				// process the auto collect return data logic
				$builder = new ReturnBuilder($node, $declared_type->value_type);
				$node->fixed_body = $builder->build_return_statements();
			}
			elseif ($declared_type !== TypeFactory::$_void && $declared_type !== TypeFactory::$_igenerator) {
				throw $this->new_syntax_error("Function required return type '{$declared_type->name}'.", $declared_type);
			}
		}
		else {
			$node->type = $infered_type ?? TypeFactory::$_void;
		}
	}

	private function check_function_declaration(FunctionDeclaration $node)
	{
		if ($node->is_checked) return;
		$node->is_checked = true;

		$this->check_parameters_for_callable_declaration($node);

		if ($node->body !== null) {
			$this->current_function = $node; // for find _SUPER
			$this->check_function_body($node);
		}
		elseif ($node->type) {
			$this->check_type($node->type, $node);
		}
		else {
			$node->type = TypeFactory::$_void;
		}
	}

	private function check_property_declaration(PropertyDeclaration $node)
	{
		if ($node->value === null && $node->type === null) {
			throw $this->new_syntax_error("The type hint required when not setted default value for property.", $node);
		}

		$infered_type = $node->value ? $this->infer_expression($node->value) : null;

		$this->set_variable_similar_declaration_type($node, $infered_type);
	}

	private function check_class_constant_declaration(ClassConstantDeclaration $node)
	{
		$infered_type = isset($node->value) ? $this->infer_expression($node->value) : null;

		if ($node->type) {
			$this->check_type($node->type, $node);
			$infered_type && $this->assert_type_compatible($node->type, $infered_type, $node->value);
		}
		elseif ($infered_type) {
			$node->type = $infered_type;
		}
		else {
			throw $this->new_syntax_error("Type required for class constant '{$node->name}'.", $node);
		}
	}

	private function check_classkindred_declaration(ClassKindredDeclaration $node)
	{
		if ($node->is_checked) return;
		$node->is_checked = true;

		// 当前是类时，包括继承的类，或实现的接口
		// 当前是接口时，包括继承的接口
		if ($node->baseds) {
			$this->attach_baseds_for_classkindred_declaration($node);
		}

		// 先检查本类中成员，推断出的类型会被后面用到
		foreach ($node->members as $member) {
			$this->check_class_member_declaration($member);
		}

		// 检查本类与继承类的成员是否匹配
		if ($node->inherits) {
			$this->check_inherts_for_class_declaration($node);
		}

		// 检查本类与实现或继承的接口中的成员是否匹配
		if ($node->baseds) {
			$this->check_baseds_for_classkindred_declaration($node);
		}

		// 本类中的成员优先级最高
		$node->actual_members = array_merge($node->actual_members, $node->members);
	}

	private function attach_baseds_for_classkindred_declaration(ClassKindredDeclaration $node)
	{
		// 类可以实现多个接口，但只能继承一个父类
		// 接口可以继承多个父接口

		$interfaces = [];
		foreach ($node->baseds as $identifier) {
			$declaration = $this->require_classkindred_declaration($identifier);

			// check is a class
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
			$node->baseds = $interfaces;
		}
	}

	private function check_inherts_for_class_declaration(ClassDeclaration $node)
	{
		// 添加到本类实际成员中，继承的成员属于super，优先级最低
		$node->actual_members = $node->inherits->symbol->declaration->actual_members;

		// 检查本类中有重写的成员是否与父类成员匹配
		foreach ($node->actual_members as $name => $super_class_member) {
			if (isset($node->members[$name])) {
				// check super class member declared in current class
				$this->assert_member_declarations($node->members[$name], $super_class_member);
			}
		}
	}

	private function check_baseds_for_classkindred_declaration(ClassKindredDeclaration $node)
	{
		// 接口中的成员默认实现属于this，优先级较高，后面接口的默认实现将覆盖前面的
		foreach ($node->baseds as $identifier) {
			$interface = $identifier->symbol->declaration;
			foreach ($interface->actual_members as $name => $member) {
				if (isset($node->members[$name])) {
					// check member declared in current class/interface
					$this->assert_member_declarations($member, $node->members[$name], true);
				}
				elseif (isset($node->actual_members[$name])) {
					// check member declared in baseds class/interfaces
					$this->assert_member_declarations($member, $node->actual_members[$name], true);

					// replace to the default method implementation in interface
					if ($member instanceof FunctionDeclaration && $member->body !== null) {
						$node->actual_members[$name] = $member;
					}
				}
				else {
					$node->actual_members[$name] = $member;
				}
			}
		}

		// 如果是类定义，最后检查是否还有未实现的接口成员
		if ($node instanceof ClassDeclaration && $node->define_mode) {
			foreach ($node->actual_members as $name => $member) {
				if ($member instanceof FunctionDeclaration && $member->body === null) {
					$interface = $member->super_block;
					throw $this->new_syntax_error("Method protocol '{$interface->name}.{$name}' required an implementation in class '{$node->name}'.", $node);
				}
			}
		}
	}

	private function assert_member_declarations(IClassMemberDeclaration $node, IClassMemberDeclaration $super, bool $is_interface = false)
	{
		// do not need check for construct
		if ($node->name === _CONSTRUCT) {
			return;
		}

		// the hint type
		if (!$this->is_strict_compatible_types($super->type, $node->type)) {
			$node_return_type = $this->get_type_name($node->type);
			$super_return_type = $this->get_type_name($super->type);

			throw $this->new_syntax_error("The return type '{$node_return_type}' in '{$node->super_block->name}.{$node->name}' must be compatible with '$super_return_type' in '{$super->super_block->name}.{$super->name}'", $node->super_block);
		}

		// the accessing modifer
		$super_modifier = $super->modifier ?? _PUBLIC;
		$this_modifier = $node->modifier ?? _PUBLIC;
		if ($super_modifier !== $this_modifier) {
			throw $this->new_syntax_error("Modifier in '{$node->super_block->name}.{$node->name}' must be same as '{$super->super_block->name}.{$super->name}'", $node->super_block);
		}

		if ($super instanceof FunctionDeclaration) {
			if (!$node instanceof FunctionDeclaration) {
				throw $this->new_syntax_error("Kind of '{$node->super_block->name}.{$node->name}' must be compatible with '{$super->super_block->name}.{$super->name}'.", $node);
			}

			$this->assert_classkindred_method_parameters($node, $super);
		}
		elseif ($super instanceof PropertyDeclaration) {
			if (!$node instanceof PropertyDeclaration) {
				throw $this->new_syntax_error("Kind of '{$node->super_block->name}.{$node->name}' must be compatible with '{$super->super_block->name}.{$super->name}'.", $node);
			}
		}
		elseif ($super instanceof ClassConstantDeclaration && $is_interface) {
			throw $this->new_syntax_error("Cannot override interface constant '{$super->super_block->name}.{$super->name}' in '{$node->super_block->name}'.", $node);
		}
	}

	private function assert_classkindred_method_parameters(FunctionDeclaration $node, FunctionDeclaration $protocol)
	{
		if ($protocol->parameters === null && $protocol->parameters === null) {
			return;
		}

		// the parameters count
		if (count($protocol->parameters) !== count($node->parameters)) {
			throw $this->new_syntax_error("Parameters of '{$node->super_block->name}.{$node->name}' must be compatible with '{$protocol->super_block->name}.{$protocol->name}'", $node->super_block);
		}

		// the parameter types
		foreach ($protocol->parameters as $idx => $protocol_param) {
			$node_param = $node->parameters[$idx];
			if (!$this->is_strict_compatible_types($protocol_param->type, $node_param->type)) {
				throw $this->new_syntax_error("Type of parameter {$idx} in '{$node->super_block->name}.{$node->name}' must be compatible with '{$protocol->super_block->name}.{$protocol->name}'", $node->super_block);
			}
		}
	}

	private function is_strict_compatible_types(IType $left, IType $right)
	{
		return $left === $right
			|| $left->symbol === $right->symbol
			|| ($left === TypeFactory::$_int && $right === TypeFactory::$_uint)
		;
	}

	private function infer_if_block(IfBlock $node): ?IType
	{
		if ($node->condition instanceof IsOperation) {
			$result_type = $this->infer_base_if_block_with_assert($node);
		}
		else {
			$result_type = $this->infer_base_if_block($node);
		}

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	protected function infer_base_if_block(BaseIfBlock $node): ?IType
	{
		$this->infer_expression($node->condition);
		$result_type = $this->infer_block($node);

		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_base_if_block_with_assert(BaseIfBlock $node): ?IType
	{
		$condition = $node->condition;
		$this->infer_expression($condition);

		$left_declaration = $condition->left->symbol->declaration;
		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = $left_declaration->type;
		$asserting_type = $condition->right;

		if ($condition->is_not) {
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
		$asserted_then_type and $left_declaration->type = $asserted_then_type;
		$result_type = $this->infer_block($node);

		if ($node->else) {
			// it would infer with the asserted else type
			$left_declaration->type = $asserted_else_type ?? $left_original_type;
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		// reset to original type
		$left_declaration->type = $left_original_type;

		return $result_type;
	}

	protected function reduce_types_with_else_block(IElseAble $node, ?IType $previous_type): ?IType
	{
		$infered_type = $this->infer_else_block($node->else);
		if ($previous_type) {
			return $this->reduce_types([$previous_type, $infered_type]);
		}

		return $infered_type;
	}

	protected function infer_else_block(IElseBlock $node): ?IType
	{
		if ($node instanceof ElseIfBlock) {
			if ($node->condition instanceof IsOperation) {
				$result_type = $this->infer_base_if_block_with_assert($node);
			}
			else {
				$result_type = $this->infer_base_if_block($node);
			}
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
		$infered_type = $this->infer_except_block($node->except);
		if ($previous_type) {
			return $this->reduce_types([$previous_type, $infered_type]);
		}

		return $infered_type;
	}

	private function infer_switch_block(SwitchBlock $node): ?IType
	{
		$testing_type = $this->infer_expression($node->test);
		if (!TypeFactory::is_when_testable_type($testing_type)) {
			throw $this->new_syntax_error("The testing expression for when-statement should be String/Int/UInt.", $node->test);
		}

		$infered_types = [];
		foreach ($node->branches as $branch) {
			if ($branch->rule instanceof ExpressionList) {
				foreach ($branch->rule->items as $rule_sub_expr) {
					$matching_type = $this->infer_expression($rule_sub_expr);
					if ($testing_type !== $matching_type) {
						throw $this->new_syntax_error("The type of matching expression should be same as testing.", $rule_sub_expr);
					}
				}
			}
			else {
				$matching_type = $this->infer_expression($branch->rule);
				if ($testing_type !== $matching_type) {
					throw $this->new_syntax_error("The type of matching expression should be same as testing.", $branch->rule);
				}
			}

			$infered_types[] = $this->infer_block($branch);
		}

		$result_type = $infered_types ? $this->reduce_types($infered_types) : null;

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
		$iterable_type = $this->infer_expression($node->iterable);
		if (!TypeFactory::is_iterable_type($iterable_type)) {
			$type_name = self::get_type_name($iterable_type);
			throw $this->new_syntax_error("Expect a Iterable type, but '{$type_name}' supplied.", $node->iterable);
		}

		// the key type, default is String
		if (isset($node->key_var) && $iterable_type instanceof ArrayType) {
			$node->key_var->symbol->declaration->type = TypeFactory::$_uint;
		}

		// the value type, default is Any
		if (isset($iterable_type->value_type)) {
			$node->value_var->symbol->declaration->type = $iterable_type->value_type;
		}

		$result_type = $this->infer_block($node);

		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_forto_block(ForToBlock $node): ?IType
	{
		$start_type = $this->expect_infered_type($node->start, TypeFactory::$_uint, TypeFactory::$_int);
		$end_type = $this->expect_infered_type($node->end, TypeFactory::$_uint, TypeFactory::$_int);

		// infer the variable type
		$node->var->symbol->declaration->type = ($start_type === TypeFactory::$_int || $end_type === TypeFactory::$_int)
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

	private function infer_single_expression_block(IBlock $block): ?IType
	{
		// maybe block to check not a sub-block, so need a temp
		$temp_block = $this->block;
		$this->block = $block;

		$infered_type = $this->infer_expression($block->body);

		$this->block = $temp_block;

		return $infered_type;
	}

	private function infer_block(IBlock $block): ?IType
	{
		// maybe block to check not a sub-block, so need a temp
		$temp_block = $this->block;
		$this->block = $block;

		$infered_types = [];
		foreach ($block->body as $statement) {
			if ($type = $this->infer_statement($statement)) {
				$infered_types[] = $type;
			}
		}

		$this->block = $temp_block;

		return $infered_types ? $this->reduce_types($infered_types, $block) : null;
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

			case VariableDeclaration::KIND:
				$this->check_variable_declaration($node);
				break;

			case ReturnStatement::KIND:
				return $this->infer_return_statement($node);

			case ExitStatement::KIND:
				$this->check_exit_statement($node);
			case BreakStatement::KIND:
			case ContinueStatement::KIND:
				$node->condition && $this->check_condition_clause($node);
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

			case SuperVariableDeclaration::KIND:
				$this->check_variable_declaration($node);
				break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unknow statement kind: '{$kind}'.", $node);
		}

		return null;
	}

	private function expect_infered_type(BaseExpression $node, IType ...$types)
	{
		$infered_type = $this->infer_expression($node);
		if (!in_array($infered_type, $types, true)) {
			$names = array_column($types, 'name');
			$names = join(' or ', $names);
			throw $this->new_syntax_error("Expect type $names, but supplied type {$infered_type->name}.", $node);
		}

		return $infered_type;
	}

	private function check_throw_statement(ThrowStatement $node)
	{
		$this->infer_expression($node->argument);
		$node->condition && $this->check_condition_clause($node);
	}

	private function infer_return_statement(ReturnStatement $node)
	{
		$infered_type = $node->argument ? $this->infer_expression($node->argument) : null;
		$node->condition && $this->check_condition_clause($node);

		return $infered_type;
	}

	private function check_exit_statement(ExitStatement $node)
	{
		$node->argument === null || $this->expect_infered_type($node->argument, TypeFactory::$_uint, TypeFactory::$_int);
		$node->condition && $this->check_condition_clause($node);
	}

	private function check_condition_clause(PostConditionAbleStatement $node)
	{
		$this->infer_expression($node->condition);
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
			throw $this->new_syntax_error("identifier '{$node->master->name}' not defined.", $node->master);
		}

		if (!$master_type instanceof ArrayType && $master_type !== TypeFactory::$_any) {
			throw $this->new_syntax_error("Cannot assign with element accessing for type '{$master_type->name}' expression.", $node->master);
		}

		if ($node->key) {
			$key_type = $this->infer_expression($node->key);
			if ($key_type !== TypeFactory::$_uint) {
				throw $this->new_syntax_error("Type for Array key expression should be int.", $node);
			}
		}

		// check the value type is valid
		$infered_type = $this->infer_expression($node->value);
		if ($master_type !== TypeFactory::$_any && $master_type->value_type) {
			$this->assert_type_compatible($master_type->value_type, $infered_type, $node->value);
		}
	}

	private function check_assignment(IAssignment $node)
	{
		$infered_type = $this->infer_expression($node->value);

		if ($infered_type === TypeFactory::$_void) {
			throw $this->new_syntax_error("Cannot use the Void type as a value.", $node->value);
		}

		$master = $node->master;
		if ($master instanceof KeyAccessing) {
			$master_type = $this->infer_key_accessing($master); // it should be not null
		}
		elseif ($master instanceof AccessingIdentifier) {
			$master_type = $this->infer_accessing_identifier($master);
		}
		else {
			// the PlainIdentifier
			$master_type = $master->symbol->declaration->type;
		}

		if (!ASTHelper::is_reassignable_expression($master)) {
			if ($master instanceof KeyAccessing) {
				throw $this->new_syntax_error("Cannot change a immutable item.", $master->left);
			}
			else {
				throw $this->new_syntax_error("Cannot assign to a final/non-assignable item.", $master);
			}
		}

		if ($master_type) {
			$this->assert_type_compatible($master_type, $infered_type, $node->value);
		}
		else {
			// just for the undeclared var
			$master->symbol->declaration->type = $infered_type;
		}
	}

	private function infer_expression(BaseExpression $node): ?IType
	{
		switch ($node::KIND) {
			case PlainIdentifier::KIND:
				$infered_type = $this->infer_plain_identifier($node);
				break;
			// case BaseType::KIND:
			// 	$infered_type = $node;
			// 	break;
			case NoneLiteral::KIND:
				$infered_type = TypeFactory::$_none;
				break;
			case FloatLiteral::KIND:
				$infered_type = TypeFactory::$_float;
				break;
			case IntegerLiteral::KIND:
				$infered_type = TypeFactory::$_uint;
				break;
			case BooleanLiteral::KIND:
				$infered_type = TypeFactory::$_bool;
				break;
			case ArrayLiteral::KIND:
				$infered_type = $this->infer_array_expression($node);
				break;
			case DictLiteral::KIND:
				$infered_type = $this->infer_dict_expression($node);
				break;
			case KeyAccessing::KIND:
				$infered_type = $this->infer_key_accessing($node);
				break;
			case UnescapedStringLiteral::KIND:
			case EscapedStringLiteral::KIND:
				$infered_type = TypeFactory::$_string;
				break;
			case EscapedStringInterpolation::KIND:
			case UnescapedStringInterpolation::KIND:
				$infered_type = $this->infer_escaped_string_interpolation($node);
				break;
			case XBlock::KIND:
				$infered_type = $this->infer_xblock($node);
				break;

			// -------
			case AccessingIdentifier::KIND:
				$infered_type = $this->infer_accessing_identifier($node);
				break;
			case VariableIdentifier::KIND:
				$infered_type = $this->infer_variable_identifier($node);
				break;
			case ClassKindredIdentifier::KIND:
				$infered_type = $this->infer_classkindred_identifier($node);
				break;
			case ConstantIdentifier::KIND:
				$infered_type = $this->infer_constant_identifier($node);
				break;
			case CallExpression::KIND:
				$infered_type = $this->infer_call_expression($node);
				break;
			case BinaryOperation::KIND:
				$infered_type = $this->infer_binary_operation($node);
				break;
			case PrefixOperation::KIND:
				$infered_type = $this->infer_prefix_operation($node);
				break;
			case Parentheses::KIND:
				$infered_type = $this->infer_expression($node->expression);
				break;
			case CastOperation::KIND:
				$infered_type = $this->infer_cast_operation($node);
				break;
			case IsOperation::KIND:
				$infered_type = $this->infer_is_operation($node);
				break;
			case HTMLEscapeExpression::KIND:
				$infered_type = $this->infer_expression($node->expression);
				break;
			case NoneCoalescingOperation::KIND:
				$infered_type = $this->infer_none_coalescing_expression($node);
				break;
			case ConditionalExpression::KIND:
				$infered_type = $this->infer_conditional_expression($node);
				break;
			case DictExpression::KIND:
				$infered_type = $this->infer_dict_expression($node);
				break;
			case ArrayExpression::KIND:
				$infered_type = $this->infer_array_expression($node);
				break;
			case LambdaExpression::KIND:
				$infered_type = $this->infer_lambda_expression($node);
				break;
			// case NSIdentifier::KIND:
			// 	$infered_type = $this->infer_ns_identifier($node);
			//	break;
			case IncludeExpression::KIND:
				$infered_type = $this->infer_include_expression($node);
				break;
			case YieldExpression::KIND:
				$infered_type = $this->infer_yield_expression($node);
				break;
			case RegularExpression::KIND:
				$infered_type = $this->infer_regular_expression($node);
				break;
			// case ReferenceOperation::KIND:
			// 	$infered_type = $this->infer_expression($node->identifier);
			// 	break;
			// case RelayExpression::KIND:
			// 	$infered_type = $this->infer_relay_expression($node);
			// 	break;
			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unknow expression kind: '{$kind}'.", $node);
		}

		return $infered_type;
	}

	private function infer_key_accessing(KeyAccessing $node): IType
	{
		$left_type = $this->infer_expression($node->left);
		$right_type = $this->infer_expression($node->right);

		if ($left_type instanceof ArrayType) {
			if ($right_type !== TypeFactory::$_uint) {
				throw $this->new_syntax_error("Index type for Array should be UInt, '{$right_type->name}' supplied.", $node);
			}

			$infered_type = $left_type->value_type;
		}
		elseif ($left_type instanceof DictType) {
			if (TypeFactory::is_dict_key_directly_supported_type($right_type)) {
				// okay
			}
			// elseif (TypeFactory::is_dict_key_castable_type($right_type)) {
			// 	// 一些类型值作为下标调用时，PHP中有一些隐式的转换规则，这些规则往往不那么清晰，容易出问题，故我们需要显示的转换
			// 	// 如false将转换为0，但实际情况可能需要的是''
			// 	// 而float如0.1将转换为0，但实际情况可能需要的是'0.1'
			// 	$node->right->infered_type = $right_type;
			// }
			else {
				throw $this->new_syntax_error("Invalid key type '{$right_type->name}' for Dict.", $node->right);
			}

			$infered_type = $left_type->value_type;
		}
		elseif ($left_type instanceof AnyType) {
			// 仅允许将实际类型为Dict的用于这类情况
			if (!TypeFactory::is_dict_key_directly_supported_type($right_type)) {
				throw $this->new_syntax_error("Key type for Dict should be String/Int/UInt, '{$right_type->name}' applied.", $node);
			}
		}
		elseif ($left_type instanceof StringType) {
			if ($right_type !== TypeFactory::$_uint && $right_type !== TypeFactory::$_int) {
				throw $this->new_syntax_error("Index type for String should be Int/UInt, '{$right_type->name}' supplied.", $node);
			}

			$infered_type = TypeFactory::$_string;
		}
		else {
			$type_name = $this->get_type_name($left_type);
			throw $this->new_syntax_error("Cannot use key accessing for type '{$type_name}'.", $node);
		}

		return $infered_type ?? TypeFactory::$_any;
	}

	private function infer_binary_operation(BinaryOperation $node): IType
	{
		$operator = $node->operator;
		$left_type = $this->infer_expression($node->left);
		$right_type = $this->infer_expression($node->right);

		if (OperatorFactory::is_number_operator($operator)) {
			if (!TypeFactory::is_number_type($left_type)) {
				$type_name = $this->get_type_name($left_type);
				throw $this->new_syntax_error("Math operation cannot use for '$type_name' type expressions.", $node->left);
			}

			if (!TypeFactory::is_number_type($right_type)) {
				$type_name = $this->get_type_name($right_type);
				throw $this->new_syntax_error("Math operation cannot use for '$type_name' type expressions.", $node->right);
			}

			if ($operator === OperatorFactory::$_division || $left_type === TypeFactory::$_float || $right_type === TypeFactory::$_float) {
				$node->infered_type = TypeFactory::$_float;
			}
			elseif ($left_type === TypeFactory::$_int || $right_type === TypeFactory::$_int) {
				$node->infered_type = TypeFactory::$_int;
			}
			else {
				$node->infered_type = TypeFactory::$_uint;
			}
		}
		elseif (OperatorFactory::is_bool_operator($operator)) {
			$node->infered_type = TypeFactory::$_bool;
		}
		elseif ($operator === OperatorFactory::$_concat) {
			// string or array
			if ($left_type instanceof ArrayType) {
				$this->assert_type_compatible($left_type, $right_type, $node->right, _CONCAT);
				$node->operator = OperatorFactory::$_vcat; // replace to array concat
				$node->infered_type = $left_type;
			}
			elseif ($left_type === TypeFactory::$_any || $left_type instanceof DictType) {
				$type_name = $this->get_type_name($left_type);
				throw $this->new_syntax_error("'concat' operation cannot use for '$type_name' type targets.", $node);
			}
			else {
				$node->infered_type = TypeFactory::$_string;
			}
		}
		// elseif ($operator === OperatorFactory::$_merge) {
		// 	// array or dict
		// 	if (!$left_type instanceof ArrayType && !$left_type instanceof DictType) {
		// 		throw $this->new_syntax_error("'merge' operation just support Array/Dict type targets.", $node);
		// 	}

		// 	$this->assert_type_compatible($left_type, $right_type, $node->right, _MERGE);
		// 	$node->infered_type = $left_type;
		// }
		elseif (OperatorFactory::is_bitwise_operator($operator)) {
			$node->infered_type = $this->reduce_types([$left_type, $right_type]);
		}
		else {
			throw $this->new_syntax_error("Unknow operator: '{$node->operator->sign}'", $node);
		}

		return $node->infered_type;
	}

	private function infer_cast_operation(CastOperation $node): IType
	{
		$this->infer_expression($node->left);
		$this->check_type($node->right, null);

		$cast_type = $node->right;

		if (!$cast_type instanceof IType) {
			throw $this->new_syntax_error("Invalid 'as' expression '{$node->right->name}'.", $node);
		}

		return $cast_type;
	}

	private function infer_is_operation(IsOperation $node): IType
	{
		$this->infer_expression($node->left);
		$this->check_type($node->right);

		$assert_type = $node->right;

		if (!$assert_type instanceof IType) {
			$kind = $node->right::KIND;
			throw $this->new_syntax_error("Invalid 'is' expression '{$kind}'.", $node);
		}

		return $assert_type;
	}

	private function infer_prefix_operation(PrefixOperation $node): IType
	{
		$infered_type = $this->infer_expression($node->expression);

		if ($node->operator === OperatorFactory::$_bool_not) {
			return TypeFactory::$_bool;
		}
		elseif ($node->operator === OperatorFactory::$_negation) {
			return $infered_type === TypeFactory::$_uint
				? TypeFactory::$_int
				: $infered_type;
		}
		// elseif ($node->operator === OperatorFactory::$_reference) {
		// 	return $infered_type;
		// }
		elseif ($node->operator === OperatorFactory::$_bitwise_not) {
			return $infered_type === TypeFactory::$_uint || $infered_type === TypeFactory::$_int || $infered_type === TypeFactory::$_float
				? TypeFactory::$_int
				: $infered_type;
		}
		else {
			throw $this->new_syntax_error("Unknow operator: '{$node->operator->sign}'", $node);
		}
	}

	private function infer_none_coalescing_expression(NoneCoalescingOperation $node): IType
	{
		$infered_types = [];
		foreach ($node->items as $item) {
			$infered_types[] = $this->infer_expression($item);
		}

		return $this->reduce_types($infered_types);
	}

	private function infer_conditional_expression(ConditionalExpression $node): IType
	{
		// infer with type assert
		if ($node->condition instanceof IsOperation) {
			return $this->infer_conditional_expression_with_assert($node);
		}

		$condition_type = $this->infer_expression($node->condition);

		if ($node->then === null) {
			$then_type = $condition_type;
		}
		else {
			$then_type = $this->infer_expression($node->then);
		}

		$else_type = $this->infer_expression($node->else);

		return $this->reduce_types([$then_type, $else_type]);
	}

	private function infer_conditional_expression_with_assert(ConditionalExpression $node): IType
	{
		$condition = $node->condition;
		$condition_type = $this->infer_expression($condition);

		$left_declaration = $condition->left->symbol->declaration;
		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = $left_declaration->type;
		$asserting_type = $condition->right;

		if ($condition->is_not) {
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
			$asserted_then_type and $left_declaration->type = $asserted_then_type;
			$then_type = $this->infer_expression($node->then);
		}

		// it would infer with the asserted else type
		$left_declaration->type = $asserted_else_type ?? $left_original_type;
		$else_type = $this->infer_expression($node->else);

		// reset to original type
		$left_declaration->type = $left_original_type;

		return $this->reduce_types([$then_type, $else_type]);
	}

	private function infer_array_expression(ArrayExpression $node): IType
	{
		if (!$node->items) {
			return TypeFactory::$_array;
		}

		$infered_value_types = [];
		foreach ($node->items as $item) {
			$infered_value_types[] = $this->infer_expression($item);
		}

		$value_type = $this->reduce_types($infered_value_types);

		return TypeFactory::create_array_type($value_type);
	}

	private function infer_dict_expression(DictExpression $node, string $name = null): IType
	{
		if (!$node->items) {
			return TypeFactory::$_dict;
		}

		$infered_value_types = [];
		foreach ($node->items as $item) {
			$key_type = $this->infer_expression($item->key);
			if (TypeFactory::is_dict_key_directly_supported_type($key_type)) {
				// okay
			}
			// elseif (TypeFactory::is_dict_key_castable_type($key_type)) {
			// 	// need cast
			// 	$item->key->infered_type = $key_type;
			// }
			else {
				throw $this->new_syntax_error("Invalid key type for Dict.", $item->key);
			}

			$infered_value_types[] = $this->infer_expression($item->value);
		}

		$value_type = $this->reduce_types($infered_value_types);

		return TypeFactory::create_dict_type($value_type);
	}

	private function infer_callback_argument(CallbackArgument $node): ?IType
	{
		if ($node->value instanceof LambdaExpression) {
			$this->infer_lambda_expression($node->value);
			return $node->value->type;
		}
		else {
			$this->infer_expression($node->value);
			return $node->value->symbol->declaration->type;
		}
	}

	private function check_type(IType $type)
	{
		if ($type instanceof BaseType) {
			if ($type instanceof ValuedType) {
				// check the value type
				$type->value_type !== null && $this->check_type($type->value_type);
			}
			elseif ($type instanceof UnionType) {
				foreach ($type->types as $member) {
					$this->check_type($member);
				}
			}
			elseif ($type instanceof CallableType) {
				$this->check_callable_type($type);
			}

			// no any other need to check
		}
		elseif ($type instanceof PlainIdentifier) {
			$infered_type = $this->infer_plain_identifier($type);
			if (!$type->symbol->declaration instanceof ClassKindredDeclaration) {
				$declare_name = $this->get_declaration_name($type->symbol->declaration);
				throw $this->new_syntax_error("Cannot use '$declare_name' as a Type.", $type);
			}
		}
		else {
			$kind = $type::KIND;
			throw $this->new_syntax_error("Unknow type kind '$kind'.", $type);
		}
	}

	private function check_callable_type(CallableType $node)
	{
		$node->is_checked = true;

		if ($node->type) {
			$this->check_type($node->type);
		}
		else {
			$node->type = TypeFactory::$_void;
		}

		$node->parameters and $this->check_parameters_for_callable_declaration($node);
	}

	private function infer_classkindred_identifier(ClassKindredIdentifier $node): IType
	{
		$declaration = $this->require_classkindred_declaration($node);
		return $declaration->type;
	}

	private function infer_constant_identifier(ConstantIdentifier $node): IType
	{
		if (!$node->symbol) {
			$this->attach_symbol($node);
		}

		return $node->symbol->declaration->type;
	}

	private function infer_yield_expression(YieldExpression $node): IType
	{
		$this->infer_expression($node->argument);
		return TypeFactory::$_any;
	}

	private function infer_include_expression(IncludeExpression $node): ?IType
	{
		$including = $this->program;
		$program = $this->require_program_declaration($node->target, $node);

		$target_main = $program->main_function;
		if ($target_main) {
			// check all expect variables is decalared in current including place
			foreach ($target_main->parameters as $parameter) {
				$param_name = $parameter->name;
				$symbol = $node->symbols[$param_name] ?? $this->find_symbol_by_name($param_name);
				if ($symbol === null) {
					throw $including->unit->new_syntax_error("Expected var '{$param_name}' to #include({$node->target}).", $node);
				}
			}

			$infered_type = $target_main->type;
		}
		else {
			$infered_type = null;
		}

		$this->program = $including;

		return $infered_type;
	}

	protected function require_program_declaration(string $name, Node $ref_node)
	{
		$program = $this->unit->programs[$name] ?? null;
		if (!$program) {
			throw $this->new_syntax_error("'{$ref_node->target}' not found.", $ref_node);
		}

		$program->unit->get_checker()->check_program($program);

		return $program;
	}

	private function infer_call_expression(CallExpression $node): ?IType
	{
		$callee = $node->callee;
		$declar = $this->require_callee_declaration($callee);

		if ($declar->type === TypeFactory::$_callable && $declar instanceof IVariableDeclaration) {
			// Callable 类型的参数或变量，该种参数或变量可接受任意能调用的值
			foreach ($node->arguments as $argument) {
				$this->infer_expression($argument);
			}

			return TypeFactory::$_any;
		}
		else {
			$this->check_call_arguments($node);
		}

		if ($callee->symbol->declaration instanceof ClassKindredDeclaration) {
			if (!$callee->symbol->declaration instanceof ClassDeclaration) {
				throw $this->new_syntax_error("Invalid call for: '{$callee->symbol->declaration->name}'", $node);
			}

			return $callee;
		}

		return $declar->type;
	}

	private function merge_callbacks_to_arguments(array &$arguments, array $callbacks, array $parameters)
	{
		if (count($callbacks) === 1 && $callbacks[0]->name === null) {
			$first_callback_parameter_on_tail = null;
			for ($i = count($parameters) - 1; $i >=0; $i--) {
				$parameter = $parameters[$i];
				if ($parameter->type instanceof CallableType) {
					$first_callback_parameter_on_tail = $parameter;
				}
				else {
					break;
				}
			}

			if (!$first_callback_parameter_on_tail) {
				throw $this->new_syntax_error("Unknow which parameter for callback.", $callbacks[0]);
			}

			$callbacks[0]->name = $first_callback_parameter_on_tail->name;
		}

		foreach ($callbacks as $cb) {
			$arguments[$cb->name] = $cb->value;
		}
	}

	private function check_call_arguments(CallExpression $node)
	{
		$src_callee_declar = $node->callee->symbol->declaration;

		// if is a variable, use it's type declaration
		if ($src_callee_declar instanceof IVariableDeclaration) {
			$callee_declar = $src_callee_declar->type;
		}
		else {
			$callee_declar = $src_callee_declar;
		}

		$arguments = $node->arguments;
		if ($callee_declar === TypeFactory::$_any || $callee_declar === TypeFactory::$_callable) {
			foreach ($arguments as $argument) {
				$this->infer_expression($argument);
			}

			return; // ignore check parameters for type Any
		}

		// if is a class, use it's construct declaration
		if ($callee_declar instanceof ClassDeclaration) {
			$callee_declar = $this->require_construct_declaration_for_class($callee_declar, $node);
			if ($callee_declar === null) {
				if ($arguments) {
					throw $this->new_syntax_error("Cannot use arguments to create a non-construct class instance.", $argument);
				}
				return;
			}
		}

		$parameters = $callee_declar->parameters;

		// the -> style callbacks
		if ($node->callbacks) {
			$this->merge_callbacks_to_arguments($arguments, $node->callbacks, $parameters);
		}

		$used_arg_names = false;
		$normalizeds = [];
		foreach ($arguments as $key => $argument) {
			if (is_numeric($key)) {
				$parameter = $parameters[$key] ?? null;
				if (!$parameter) {
					throw $this->new_syntax_error("Argument $key does not matched parameter defined in '{$src_callee_declar->name}'.", $argument);
				}

				$idx = $key;
			}
			else {
				$used_arg_names = true;
				list($idx, $parameter) = $this->require_parameter_by_name($key, $parameters, $node->callee);
			}

			// check type is match
			$infered_type = $this->infer_expression($argument);
			if (!$parameter->type->is_accept_type($infered_type)) {
				$callee_name = self::get_declaration_name($callee_declar);

				$expected_type_name = self::get_type_name($parameter->type);
				$infered_type_name = self::get_type_name($infered_type);

				if (!is_int($key)) {
					$key = "'$key'";
				}

				throw $this->new_syntax_error("Type of argument $key does not matched the parameter for '{$callee_name}', expected '{$expected_type_name}', supplied '{$infered_type_name}'.", $argument);
			}

			// if ($parameter->is_referenced) {
			// 	if (!ASTHelper::is_reassignable_expression($argument)) {
			// 		throw $this->new_syntax_error("Argument $key is invalid for the referenced parameter defined in '{$src_callee_declar->name}'.", $argument);
			// 	}
			// }

			if ($parameter->is_value_mutable) {
				if (!ASTHelper::is_value_mutable($argument)) {
					throw $this->new_syntax_error("Argument $key is invalid for the value-mutable parameter defined in '{$src_callee_declar->name}'.", $argument);
				}
			}

			$normalizeds[$idx] = $argument;
		}

		// check is has any required parameter
		foreach ($parameters as $idx => $parameter) {
			if ($parameter->value === null && !isset($normalizeds[$idx])) {
				$callee_name = self::get_declaration_name($callee_declar);
				throw $this->new_syntax_error("Missed argument $idx to call '{$callee_name}'.", $node);
			}
		}

		// fill the default value when needed
		if ($used_arg_names) {
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

	private function require_construct_declaration_for_class(ClassDeclaration $class, CallExpression $call)
	{
		$symbol = $this->find_member_symbol_in_class($class, _CONSTRUCT);

		if (!$symbol) {
			if ($call->arguments || $call->callbacks) {
				throw $this->new_syntax_error("'construct' of class '{$class->name}' not found.", $class);
			}

			return; // no any to check
		}

		return $symbol->declaration;
	}

	private function check_type_compatible(IType $left, IType $right, Node $value_node)
	{
		if ($left->is_accept_type($right)) {
			return true;
		}

		// for [] / [:]
		if (($value_node instanceof ArrayLiteral || $value_node instanceof DictLiteral) && !$value_node->items) {
			return true;
		}

		return false;
	}

	private function assert_type_compatible(IType $left, IType $right, Node $value_node, string $kind = 'assign')
	{
		if (!$this->check_type_compatible($left, $right, $value_node)) {
			$left_type_name = self::get_type_name($left);
			$right_type_name = self::get_type_name($right);
			throw $this->new_syntax_error("It's not compatible for type '{$left_type_name}' {$kind} with '{$right_type_name}'.", $value_node);
		}
	}

	// @return [index, ParameterDeclaration]
	private function require_parameter_by_name(string $name, array $parameters, ICallee $callee_node)
	{
		foreach ($parameters as $idx => $parameter) {
			if ($parameter->name === $name) {
				return [$idx, $parameter];
			}
		}

		throw $this->new_syntax_error("Argument '$name' deliver to callee '{$callee_node->name}' not found in it declaration.", $callee_node);
	}

	// return [index, CallbackProtocol]
	private function require_callback_protocol_by_name(string $name, array $callbacks, ICallee $callee_node)
	{
		foreach ($callbacks as $idx => $callback) {
			if ($callback->name === $name) {
				return [$idx, $callback];
			}
		}

		throw $this->new_syntax_error("Callback argument '$name' deliver to callee '{$callee_node->name}' not found in declaration.", $callee_node);
	}

	private function infer_plain_identifier(PlainIdentifier $node): IType
	{
		if (!$node->symbol) {
			$this->attach_symbol($node);
		}

		$declaration = $node->symbol->declaration;

		if ($declaration instanceof VariableDeclaration || $declaration instanceof ConstantDeclaration || $declaration instanceof ParameterDeclaration || $declaration instanceof ClassKindredDeclaration) {
			if (!$declaration->type) {
				throw $this->new_syntax_error("Declaration of '{$node->name}' not found.", $node);
			}

			$type = $declaration->type;
		}
		elseif ($declaration instanceof ICallableDeclaration) {
			$type = TypeFactory::$_callable;
		}
		// elseif ($declaration instanceof NamespaceDeclaration) {
		// 	$type = TypeFactory::$_namespace;
		// }
		else {
			throw new UnexpectNode($declaration);
		}

		return $type;
	}

	private function infer_accessing_identifier(AccessingIdentifier $node): ?IType
	{
		$member = $this->require_accessing_identifier_declaration($node);
		switch ($member::KIND) {
			case FunctionDeclaration::KIND:
				return TypeFactory::$_callable;

			case MaskedDeclaration::KIND:
				if ($member->parameters !== null) {
					throw $this->new_syntax_error("Cannot use the masked function '$member->name' without '()'", $node);
				}
				// unbreak
			case PropertyDeclaration::KIND:
			case ClassConstantDeclaration::KIND:
			case ClassDeclaration::KIND:
				return $member->type;

			// case NamespaceDeclaration::KIND:
			// 	return TypeFactory::$_namespace;

			default:
				throw new UnexpectNode($member);
		}
	}

	private function infer_regular_expression(RegularExpression $node): IType
	{
		return TypeFactory::$_regex;
	}

	private function infer_escaped_string_interpolation(EscapedStringInterpolation $node): IType
	{
		foreach ($node->items as $item) {
			if (is_object($item) && !$item instanceof ILiteral) {
				$this->infer_expression($item);
			}
		}

		return TypeFactory::$_string;
	}

	private function infer_variable_identifier(VariableIdentifier $node): IType
	{
		return $this->attach_symbol($node)->declaration->type;
	}

	private function infer_xblock(XBlock $node): IType
	{
		foreach ($node->items as $item) {
			if ($item instanceof XBlockElement) {
				$this->check_xblock_element($item);
			}
		}

		return TypeFactory::$_xview;
	}

	private function check_xblock_element(XBlockElement $node)
	{
		if ($node->name instanceof BaseExpression) {
			$this->infer_expression($node->name);
		}

		if ($node->attributes) {
			foreach ($node->attributes as $item) {
				if ($item instanceof XBlockElement) {
					$this->check_xblock_element($item);
				}
				elseif (is_object($item)) {
					$this->infer_expression($item);
				}
			}
		}

		if ($node->children) {
			foreach ($node->children as $item) {
				if ($item instanceof XBlockElement) {
					$this->check_xblock_element($item);
				}
				elseif (is_object($item)) {
					$this->infer_expression($item);
				}
			}
		}
	}

	private function attach_symbol(PlainIdentifier $node)
	{
		$symbol = $this->find_symbol_by_name($node->name, $node);
		if ($symbol === null) {
			throw $this->new_syntax_error("Symbol of '{$node->name}' not found.", $node);
		}

		$node->symbol = $symbol;
		return $symbol;
	}

	private function find_symbol_by_name(string $name, Node $node = null)
	{
		$symbol = $this->find_symbol_in_program($this->program, $name);

		// find for 'super'
		if ($symbol === null && $name === _SUPER) {
			if ($this->current_function->super_block->inherits === null) {
				throw $this->new_syntax_error("There are not inherits a class/interface for 'super' reference.", $node);
			}

			$inherits_class = $this->current_function->super_block->inherits->symbol->declaration;
			if ($this->current_function->is_static) {
				$symbol = $inherits_class->this_class_symbol;
			}
			else {
				$symbol = $inherits_class->this_object_symbol;
			}
		}

		if ($symbol) {
			if ($symbol->declaration instanceof UseDeclaration) {
				$symbol->declaration = $this->attach_source_declaration_for_use($symbol->declaration);
			}
			elseif (!$symbol->declaration->is_checked) {
				$this->check_declaration($symbol->declaration);
			}
		}

		// find in unit level symbols
		return $symbol;
	}

	private function find_symbol_in_program(Program $program, string $name)
	{
		if (isset($program->symbols[$name])) {
			$symbol = $program->symbols[$name];
		}
		elseif (isset($program->unit->symbols[$name])) {
			$symbol = $program->unit->symbols[$name];
			$symbol->declaration->is_unit_level = true;
		}
		else {
			$symbol = null;
		}

		return $symbol;
	}

	private function require_callee_declaration(ICallee $node): IDeclaration
	{
		if ($node instanceof AccessingIdentifier) {
			$declar = $this->require_accessing_identifier_declaration($node);
		}
		elseif ($node instanceof PlainIdentifier) {
			$node->symbol || $this->attach_symbol($node);

			$declar = $node->symbol->declaration;
			if ($declar instanceof ICallableDeclaration) {
				$declar->type === null && $this->check_callable_declaration($declar);
			}
			elseif ($declar->type instanceof ICallableDeclaration) {
				$declar = $declar->type;
			}
			elseif ($declar->type === TypeFactory::$_callable) {
				// for Callable type parameters
			}
			else {
				throw $this->new_syntax_error("Invalid callable expression.", $node);
			}
		}
		elseif ($node instanceof ClassKindredIdentifier) {
			$declar = $this->require_classkindred_declaration($node);
		}
		else {
			$kind = $node::KIND;
			throw $this->new_syntax_error("Unknow callee kind: '$kind'.", $node);
		}

		return $declar;
	}

	private function check_callable_declaration(ICallableDeclaration $node)
	{
		if ($node->is_checking) {
			throw $this->new_syntax_error("Function '{$node->name}' has a circular checking, needs a return type.", $node);
		}

		$node->is_checking = true;

		switch ($node::KIND) {
			case FunctionDeclaration::KIND:
				$this->check_function_declaration($node);
				break;

			case LambdaExpression::KIND:
				$this->infer_lambda_expression($node);
				break;

			// case CallbackProtocol::KIND:
			// 	break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unexpect callable declaration kind: '{$kind}'.", $node);
		}
	}

	private function require_function_declaration(PlainIdentifier $node): FunctionDeclaration
	{
		if (!$node->symbol) {
			if (!isset($this->unit->symbols[$node->name])) {
				throw $this->new_syntax_error("Symbol '{$node->name}' not found.", $node);
			}

			$node->symbol = $this->unit->symbols[$node->name];

			$this->check_function_declaration($node->symbol->declaration);
		}

		return $node->symbol->declaration;
	}

	private function require_accessing_identifier_declaration(AccessingIdentifier $node): IMemberDeclaration
	{
		$master = $node->master;
		$master_type = $this->infer_expression($master);

		if ($master_type instanceof BaseType) {
			if ($master_type === TypeFactory::$_any) {
				// let member type to Any on master is Any
				$this->create_any_symbol_for_accessing_identifier($node);
			}
			// elseif ($master_type === TypeFactory::$_namespace) {
			// 	$this->attach_namespace_member_symbol($master->symbol->declaration, $node);
			// }
			elseif ($master_type instanceof MetaType) { // includes static call for class members
				$declaration = $master->symbol->declaration;
				if (!$declaration instanceof ClassDeclaration) {
					$declaration = $declaration->type->value_type->symbol->declaration;
				}

				$node->symbol = $this->require_class_member_symbol($declaration, $node);

				if (!$node->symbol->declaration->is_static) {
					throw $this->new_syntax_error("Invalid to accessing a non-static member.", $node);
				}

				if (!$node->symbol->declaration->is_accessable($master)) {
					throw $this->new_syntax_error("Cannot access a internal/private member.", $node);
				}
			}
			else {
				$node->symbol = $this->require_class_member_symbol($master_type->symbol->declaration, $node);
				// $node->symbol->declaration->type || $this->check_class_member_declaration($node->symbol->declaration);
			}
		}
		elseif ($master_type instanceof Identifiable) { // the master would be an object expression
			$node->symbol = $this->require_class_member_symbol($master_type->symbol->declaration, $node);
			// $node->symbol->declaration->type || $this->check_class_member_declaration($node->symbol->declaration);

			if (!$node->symbol->declaration->is_accessable($master)) {
				throw $this->new_syntax_error("Cannot access the internal/private members.", $node);
			}
		}
		else {
			$type_name = $this->get_type_name($master_type);
			throw $this->new_syntax_error("Invalid accessable type '$type_name'.", $master);
		}

		return $node->symbol->declaration;
	}

	private function create_any_symbol_for_accessing_identifier(AccessingIdentifier $node)
	{
		$node->symbol = new Symbol(ASTFactory::$virtual_property_for_any, $node->name);
	}

	private function find_member_symbol_in_class(ClassKindredDeclaration $classkindred, string $member_name): ?Symbol
	{
		// 当作为外部调用时，应命中这里的逻辑
		$declaration = $classkindred->actual_members[$member_name] ?? null;
		if ($declaration) {
			return $declaration->symbol;
		}

		// 当调用的地方为正在检查的类中时，需要走以下逻辑

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
		if ($classkindred->inherits) {
			$symbol = $this->find_member_symbol_in_class($classkindred->inherits->symbol->declaration, $member_name);
			if ($symbol) {
				return $symbol;
			}
		}

		// find in implements interfaces
		if ($classkindred->baseds) {
			foreach ($classkindred->baseds as $interface) {
				if (!$interface->symbol) {
					$this->require_classkindred_declaration($interface);
				}

				$symbol = $this->find_member_symbol_in_class($interface->symbol->declaration, $member_name);
				if ($symbol) {
					return $symbol;
				}
			}
		}

		return null;
	}

	private function require_class_member_symbol(ClassKindredDeclaration $classkindred, Identifiable $node): Symbol
	{
		$symbol = $this->find_member_symbol_in_class($classkindred, $node->name);
		if (!$symbol) {
			throw $this->new_syntax_error("Member '{$node->name}' not found in '{$classkindred->name}'", $node);
		}

		return $symbol;
	}

	// private function attach_namespace_member_symbol(NamespaceDeclaration $ns_declaration, AccessingIdentifier $node)
	// {
	// 	$node->symbol = $ns_declaration->symbols[$node->name] ?? null;
	// 	if (!$node->symbol) {
	// 		throw $this->new_syntax_error("Symbol '{$node->name}' not found in '{$ns_declaration->name}'.", $node);
	// 	}

	// 	return $node->symbol;
	// }

	// includes builtin types, and classes, and namespace
	private function require_object_declaration(BaseExpression $node): ClassKindredDeclaration
	{
		$infered_type = $this->infer_expression($node);
		if (!$infered_type instanceof IType) {
			throw new UnexpectNode($node);
		}

		if ($infered_type === TypeFactory::$_namespace) {
			return $node->declaration;
		}

		return $infered_type->symbol->declaration;
	}

	private function require_classkindred_declaration(PlainIdentifier $identifier)
	{
		$symbol = $identifier->symbol;

		if (!$symbol) {
			if ($identifier->ns) {
				$ns_symbol = $this->require_global_symbol_for_identifier($identifier->ns);
				$symbol = $this->require_symbol_for_namespace($ns_symbol->declaration->ns, $identifier->name);
			}
			else {
				$symbol = $this->require_global_symbol_for_identifier($identifier);
				if ($symbol->declaration instanceof UseDeclaration) {
					$symbol->declaration = $this->attach_source_declaration_for_use($symbol->declaration);
				}
			}

			if (!$symbol->declaration instanceof ClassKindredDeclaration) {
				throw $this->new_syntax_error("Declaration of '{$identifier->name}' not a classkindred declaration.", $identifier);
			}

			$identifier->symbol = $symbol;
		}

		$declaration = $symbol->declaration;

		$temp_program = $this->program;
		$this->program = $declaration->program;
		$this->check_classkindred_declaration($declaration);
		$this->program = $temp_program;

		return $declaration;
	}

	private function require_global_symbol_for_identifier(Identifiable $identifier)
	{
		$symbol = $this->program->symbols[$identifier->name] ?? $this->program->unit->symbols[$identifier->name] ?? null;
		if ($symbol === null) {
			throw $this->new_syntax_error("Symbol '{$identifier->name}' not found.", $identifier);
		}

		return $symbol;
	}

	private function require_symbol_for_namespace(NSIdentifier $ns, string $name)
	{
		$symbol = $this->require_unit($ns)->symbols[$name] ?? null;
		if ($symbol === null) {
			throw $this->new_syntax_error("Target '{$ns->uri}.{$name}' for use not found in unit '{$ns->uri}'.", $ns);
		}

		return $symbol;
	}

	private function attach_source_declaration_for_use(UseDeclaration $use): IRootDeclaration
	{
		if ($use->source_declaration === null) {
			// find from the target Unit
			$name = $use->source_name ?? $use->name;
			$symbol = $this->require_unit($use->ns)->symbols[$name] ?? null;
			if ($symbol === null) {
				throw $this->new_syntax_error("Target '{$name}' for use not found in unit '{$use->ns->uri}'.", $use);
			}

			$target = $symbol->declaration;
			if (!$target->is_checked) {
				$target->program->unit->get_checker()->check_declaration($target);
			}

			$use->source_declaration = $target;
			$use->is_checked = true;
		}

		return $use->source_declaration;
	}

	private function require_unit(NSIdentifier $ns): Unit
	{
		$ns_uri = $ns->uri;
		$program = $this->unit->use_units[$ns_uri] ?? null;
		if (!$program) {
			throw $this->new_syntax_error("Unit '{$ns_uri}' not found.", $ns);
		}

		return $program;
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

		if ($result_type === TypeFactory::$_any) {
			return TypeFactory::$_any;
		}

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

			$place = "Near $place.";
		}

		$message = "Syntax check error:\n{$place}\n{$message}";
		DEBUG && $message .= "\n\nTraces:\n" . get_traces();

		return new \Exception($message);
	}

	static function get_declaration_name(IDeclaration $declaration)
	{
		if (isset($declaration->super_block) && $declaration->super_block instanceof ClassDeclaration) {
			return "{$declaration->super_block->name}.{$declaration->name}";
		}

		return $declaration->name;
	}

	static function get_type_name(IType $type)
	{
		if ($type instanceof IterableType) {
			if ($type->value_type === null) {
				$value_type_name = 'Any';
			}
			else {
				$value_type_name = self::get_type_name($type->value_type);
			}

			$name = "{$value_type_name}.{$type->name}";
		}
		elseif ($type instanceof MetaType) {
			$value_type_name = self::get_type_name($type->value_type);
			$name = "{$value_type_name}." . _DOT_SIGN_METATYPE;
		}
		elseif ($type instanceof ICallableDeclaration) {
			$args = [];
			foreach ($type->parameters as $param) {
				$args[] = static::get_type_name($param->type);
			}

			$name = '(' . join(', ', $args) . ') ' . static::get_type_name($type->type);
		}
		elseif ($type instanceof UnionType) {
			$names = [];
			foreach ($type->types as $member) {
				$names[] = static::get_type_name($member);
			}

			$name = join('|', $names);
		}
		else {
			$name = $type->name;
		}

		return $name;
	}
}

class UnexpectNode extends \Exception
{
	public function __construct(Node $node)
	{
		$kind = $node::KIND;
		$this->message = new \Exception("Unexpect node kind: '$kind'.");
	}
}

// end
