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
	 * @var BaseBlock
	 */
	private $block;

	public function __construct(Unit $unit)
	{
		$this->unit = $unit;
	}

	public function check_program(Program $program)
	{
		if ($program->checked) return;
		$program->checked = true;

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
		$this->require_declaration_for_use($node);
	}

	private function check_declaration(IDeclaration $node)
	{
		if ($node->checked) {
			return $node;
		}

		$temp_program = $this->program;
		$this->program = $node->program;

		switch ($node::KIND) {
			case ConstantDeclaration::KIND:
				$this->check_constant_declaration($node);
				break;

			case MainFunctionBlock::KIND:
			case FunctionBlock::KIND:
				$this->check_function_block($node);
				break;

			case FunctionDeclaration::KIND:
				$this->check_function_declaration($node);
				break;

			case ClassDeclaration::KIND:
				$this->check_classlike_declaration($node);
				break;

			case InterfaceDeclaration::KIND:
				$this->check_classlike_declaration($node);
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
			case FunctionBlock::KIND:
				$this->check_function_block($node);
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

			case MaskedDeclaration::KIND:
				$this->check_masked_declaration($node);
				break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unexpect class/interface member declaration kind: '{$kind}'", $node);
		}
	}

	private function check_constant_declaration(ConstantDeclaration $node)
	{
		$node->checked = true;
		$value = $node->value;

		// no value, it should be declare mode
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
					throw $this->new_syntax_error("Array concat operation cannot use as a constant value.", $value);
				}
			}
			elseif ($value->operator === OperatorFactory::$_merge) {
				throw $this->new_syntax_error("Array/Dict merge operation cannot use as a constant value.", $value);
			}
		}

		if ($node->type) {
			$this->check_type($node->type, $node);
			$infered_type && $this->assert_type_compatible($node->type, $infered_type, $node->value);
		}
		else {
			$node->type = $infered_type;
		}
	}

	private function check_is_constant_expression(IExpression $node): bool
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

	private function check_variable_declaration(VariableDeclaration $node)
	{
		$node->checked = true;

		$infered_type = $node->value ? $this->infer_expression($node->value) : null;

		if ($node->type) {
			$this->check_type($node->type, $node);
			$infered_type && $this->assert_type_compatible($node->type, $infered_type, $node->value);
		}
		else {
			$node->type = $infered_type;
		}
	}

	private function check_parameters_for_callable_declaration(ICallableDeclaration $callable)
	{
		$this->check_parameters_for_node($callable);

		// if ($callable->callbacks) {
		// 	foreach ($callable->callbacks as $callback) {
		// 		$this->check_callback_protocol($callback);
		// 	}
		// }
	}

	private function check_parameters_for_node($node)
	{
		foreach ($node->parameters as $parameter) {
			$parameter->checked = true;
			$infered_type = $parameter->value ? $this->infer_expression($parameter->value) : null;

			if ($parameter->type) {
				$this->check_type($parameter->type, $node);
				$infered_type && $this->assert_type_compatible($parameter->type, $infered_type, $parameter->value);
			}
			else {
				$parameter->type = $infered_type === null || $infered_type === TypeFactory::$_none
					? TypeFactory::$_any
					: $infered_type;
			}
		}
	}

	private function check_callback_protocol(CallbackProtocol $node)
	{
		$node->checked = true;

		if ($node->type) {
			$this->check_type($node->type);
		}
		else {
			$node->type = TypeFactory::$_void;
		}

		$this->check_parameters_for_callable_declaration($node);
	}

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
		// if ($node->callbacks) {
		// 	$parameters = array_merge($parameters, $node->callbacks);
		// }

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
		$this->checked = true;
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
					throw $this->new_syntax_error("'this' or 'super' cannot use in lambda functions.", $node);
				}

				// for lambda use in php
				$node->use_variables[$identifier->name] = $identifier;
			}
		}

		$this->check_parameters_for_callable_declaration($node);
		$this->check_function_body($node);

		return TypeFactory::create_callable_type($node->type, $node->parameters);
	}

	private function check_function_body(IEnclosingBlock $node)
	{
		if (is_array($node->body)) {
			$infered_type = $this->infer_block($node);
		}
		else {
			$infered_type = $this->infer_single_expression_block($node);
		}

		if ($node->type) {
			$this->check_type($node->type, $node);

			if ($infered_type !== null) {
				if (!$node->type->is_accept_type($infered_type)) {
					throw $this->new_syntax_error("Function '{$node->name}' return type is '{$infered_type->name}', not compatible with the declared '{$node->type->name}'.", $node);
				}
			}
			elseif (!empty($node->type->is_collect_mode)) {
				// process the auto collect return data logic
				$builder = new ReturnBuilder($node, $node->type->value_type);
				$node->fixed_body = $builder->build_return_statements();
			}
			elseif ($node->type !== TypeFactory::$_void && empty($node->has_yield)) {
				throw $this->new_syntax_error("Function required return type '{$node->type->name}'.", $node->type);
			}
		}
		else {
			$node->type = $infered_type ?? TypeFactory::$_void;
		}
	}

	private function check_function_block(FunctionBlock $node)
	{
		if ($node->checked) return;
		$node->checked = true;

		// // create _THIS / _SUPER symbols for static method
		// if ($node->is_static) {
		// 	$this->create_class_symbols_for_static_method($node);
		// }

		$this->current_function = $node; // for find _SUPER

		$this->check_parameters_for_callable_declaration($node);
		$this->check_function_body($node);
	}

	// private function create_class_symbols_for_static_method(FunctionBlock $node)
	// {
	// 	$class = $node->super_block;
	// 	$node->symbols[_THIS] = new Symbol($class);

	// 	if ($class->inherits) {
	// 		$node->symbols[_SUPER] = new Symbol($class->inherits->symbol->declaration);
	// 	}
	// }

	private function check_function_declaration(FunctionDeclaration $node)
	{
		if ($node->checked) return;
		$node->checked = true;

		$this->check_parameters_for_callable_declaration($node);

		if ($node->type) {
			$this->check_type($node->type, $node);
		}
		else {
			$node->type = TypeFactory::$_void;
		}
	}

	private function check_function(IFunctionDeclaration $node)
	{
		if ($node instanceof FunctionBlock) {
			$this->check_function_block($node);
		}
		else {
			$this->check_function_declaration($node);
		}
	}

	private function check_property_declaration(PropertyDeclaration $node)
	{
		$infered_type = $node->value ? $this->infer_expression($node->value) : null;

		if ($node->type) {
			$this->check_type($node->type, $node);
			$infered_type && $this->assert_type_compatible($node->type, $infered_type, $node->value);
		}
		elseif ($infered_type) {
			$node->type = $infered_type;
		}
		else {
			throw $this->new_syntax_error("Type required for property '{$node->name}'.", $node);
		}
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

	private function check_classlike_declaration(ClassLikeDeclaration $node)
	{
		if ($node->checked) return;
		$node->checked = true;

		// 当前是类时，包括继承的类，或实现的接口
		// 当前是接口时，包括继承的接口
		if ($node->baseds) {
			$this->attach_baseds_for_classlike_declaration($node);
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
			$this->check_baseds_for_classlike_declaration($node);
		}

		// 本类中的成员优先级最高
		$node->actual_members = array_merge($node->actual_members, $node->members);
	}

	private function attach_baseds_for_classlike_declaration(ClassLikeDeclaration $node)
	{
		// 类可以实现多个接口，但只能继承一个父类
		// 接口可以继承多个父接口

		$interfaces = [];
		foreach ($node->baseds as $identifier) {
			$declaration = $this->require_classlike_declaration($identifier);

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

			// // add super symbol for current class declaration
			// // super keyword could not to access the implements interfaces
			// $node->symbols[_SUPER] = ASTHelper::create_symbol_super($node->inherits);
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
				$this->assert_classlike_member_declaration($node->members[$name], $super_class_member);
			}
		}
	}

	private function check_baseds_for_classlike_declaration(ClassLikeDeclaration $node)
	{
		// 接口中的成员默认实现属于this，优先级较高，后面接口的默认实现将覆盖前面的
		foreach ($node->baseds as $identifier) {
			$interface = $identifier->symbol->declaration;
			foreach ($interface->actual_members as $name => $member) {
				if (isset($node->members[$name])) {
					// check member declared in current class/interface
					$this->assert_classlike_member_declaration($node->members[$name], $member);
				}
				elseif (isset($node->actual_members[$name])) {
					// check member declared in baseds class/interfaces
					$this->assert_classlike_member_declaration($node->actual_members[$name], $member);

					// replace to the default method implementation in interface
					if ($member instanceof FunctionBlock) {
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
				if (!$member instanceof FunctionDeclaration
					|| $member instanceof FunctionBlock
					|| $member->super_block instanceof ClassDeclaration
				) {
					continue;
				}

				$class = $member->super_block;
				throw $this->new_syntax_error("Method protocol '{$class->name}.{$name}' required an implementation in class '{$node->name}'.", $node);
			}
		}
	}

	private function assert_classlike_member_declaration(IClassMemberDeclaration $definition, IClassMemberDeclaration $protocol)
	{
		// do not need check for construct
		if ($definition->name === _CONSTRUCT) {
			return;
		}

		// the hint type
		if (!$this->is_strict_compatible_types($protocol->type, $definition->type)) {
			$defined_return_type = $this->get_type_name($definition->type);
			$protocol_return_type = $this->get_type_name($protocol->type);

			throw $this->new_syntax_error("Type '{$defined_return_type}' in '{$definition->super_block->name}.{$definition->name}' must be compatible with '$protocol_return_type' in interface '{$protocol->super_block->name}.{$protocol->name}'", $definition->super_block);
		}

		if ($protocol instanceof FunctionDeclaration) {
			if (!$definition instanceof FunctionDeclaration) {
				throw $this->new_syntax_error("Kind of definition '{$definition->super_block->name}.{$definition->name}' must be compatible with '{$protocol->super_block->name}.{$protocol->name}'.");
			}

			$this->assert_classlike_method_parameters($definition, $protocol);
		}
		elseif ($protocol instanceof PropertyDeclaration) {
			if (!$definition instanceof PropertyDeclaration) {
				throw $this->new_syntax_error("Kind of definition '{$definition->super_block->name}.{$definition->name}' must be compatible with '{$protocol->super_block->name}.{$protocol->name}'.");
			}
		}
		elseif ($protocol instanceof ClassConstantDeclaration) {
			throw $this->new_syntax_error("Cannot override constant '{$protocol->super_block->name}.{$protocol->name}' in '{$definition->super_block->name}'.");
		}
	}

	private function assert_classlike_method_parameters(FunctionDeclaration $definition, FunctionDeclaration $protocol)
	{
		if ($protocol->parameters === null && $protocol->parameters === null) {
			return;
		}

		// the parameters count
		if (count($protocol->parameters) !== count($definition->parameters)) {
			throw $this->new_syntax_error("Parameters of '{$definition->super_block->name}.{$definition->name}' must be compatible with '{$protocol->super_block->name}.{$protocol->name}'", $definition->super_block);
		}

		// the parameter types
		foreach ($protocol->parameters as $idx => $protocol_param) {
			$definition_param = $definition->parameters[$idx];
			if (!$this->is_strict_compatible_types($protocol_param->type, $definition_param->type)) {
				throw $this->new_syntax_error("Type of parameter {$idx} in '{$definition->super_block->name}.{$definition->name}' must be compatible with '{$protocol->super_block->name}.{$protocol->name}'", $definition->super_block);
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
		$this->infer_expression($node->condition);
		$result_type = $this->infer_block($node);

		if ($node->else) {
			$else_type = $this->infer_else_block($node->else);
			$result_type = $this->reduce_types([$result_type, $else_type], $node);
		}

		if ($node->except) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	protected function infer_else_block(IElseBlock $node): ?IType
	{
		if ($node instanceof ElseIfBlock) {
			$this->infer_expression($node->condition);
		}

		$result_type = $this->infer_block($node);

		if (isset($node->else)) {
			$else_type = $this->infer_else_block($node->else);
			$result_type = $this->reduce_types([$result_type, $else_type], $node);
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

	private function reduce_types_with_except_block(BaseBlock $node, ?IType $previous_type)
	{
		$except_type = $this->infer_except_block($node->except);
		if ($previous_type) {
			return $this->reduce_types([$previous_type, $except_type], $node);
		}

		return $except_type;
	}

	private function infer_case_block(CaseBlock $node): ?IType
	{
		$testing_type = $this->infer_expression($node->test);
		if (!TypeFactory::is_case_testable_type($testing_type)) {
			throw $this->new_syntax_error("The testing expression for case-statement should be String/Int/UInt.", $node->test);
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

		return $this->reduce_types($infered_types, $node);
	}

	private function infer_forin_block(ForInBlock $node): ?IType
	{
		$iterable_type = $this->infer_expression($node->iterable);
		if (!TypeFactory::is_iterable_type($iterable_type)) {
			$type_name = self::get_type_name($iterable_type);
			throw $this->new_syntax_error("Expect a Iterable type, but {$type_name} supplied.", $node->iterable);
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
			$else_type = $this->infer_else_block($node->else);
			$result_type = $this->reduce_types([$result_type, $else_type], $node);
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
			$else_type = $this->infer_else_block($node->else);
			$result_type = $this->reduce_types([$result_type, $else_type], $node);
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

	private function infer_single_expression_block(BaseBlock $block): ?IType
	{
		// maybe block to check not a sub-block, so need a temp
		$temp_block = $this->block;
		$this->block = $block;

		$infered_type = $this->infer_expression($block->body);

		$this->block = $temp_block;

		return $infered_type;
	}

	private function infer_block(BaseBlock $block): ?IType
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

		return $this->reduce_types($infered_types, $block);
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
				$node->condition && $this->check_when_clause($node);
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

			case CaseBlock::KIND:
				return $this->infer_case_block($node);

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

	private function expect_infered_type(IExpression $node, IType ...$types)
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
		$node->condition && $this->check_when_clause($node);
	}

	private function infer_return_statement(ReturnStatement $node)
	{
		$infered_type = $node->argument ? $this->infer_expression($node->argument) : null;
		$node->condition && $this->check_when_clause($node);

		return $infered_type;
	}

	private function check_exit_statement(ExitStatement $node)
	{
		$node->argument === null || $this->expect_infered_type($node->argument, TypeFactory::$_uint, TypeFactory::$_int);
		$node->condition && $this->check_when_clause($node);
	}

	private function check_when_clause(PostConditionAbleStatement $node)
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
			// just like: items['abc'] = 123
			$master_type = $this->infer_key_accessing($master); // it should be not null
		}
		else {
			if ($master->symbol) {
				$master_type = $master->symbol->declaration->type;
			}
			else {
				$master_type = $this->infer_expression($master);
			}
		}

		if (!ASTHelper::is_assignable_expression($master)) {
			throw $this->new_syntax_error("Invalid expression to assign.", $master);
		}

		if ($master_type) {
			$this->assert_type_compatible($master_type, $infered_type, $node->value);
		}
		else {
			// just for the undeclared var
			$master->symbol->declaration->type = $infered_type;
		}
	}

	private function infer_expression(IExpression $node): ?IType
	{
		switch ($node::KIND) {
			case PlainIdentifier::KIND:
				$infered_type = $this->infer_plain_identifier($node);
				break;
			case BaseType::KIND:
				$infered_type = $node;
				break;
			case NoneLiteral::KIND:
				$infered_type = TypeFactory::$_none;
				break;
			case FloatLiteral::KIND:
				$infered_type = TypeFactory::$_float;
				break;
			case IntegerLiteral::KIND:
				$infered_type = TypeFactory::$_int;
				break;
			case UnsignedIntegerLiteral::KIND:
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
				$infered_type = $this->infer_variable($node);
				break;
			case ClassLikeIdentifier::KIND:
				$infered_type = $this->infer_classlike_identifier($node);
				break;
			case ConstantIdentifier::KIND:
				$infered_type = $this->infer_constant($node);
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
			case AsOperation::KIND:
				$infered_type = $this->infer_as_operation($node);
				break;
			case IsOperation::KIND:
				$infered_type = $this->infer_is_operation($node);
				break;
			case HTMLEscapeExpression::KIND:
				$infered_type = $this->infer_expression($node->expression);
				break;
			case ConditionalExpression::KIND:
				$infered_type = $this->infer_conditional_expression($node);
				break;
			// case FunctionalOperation::KIND:
			// 	$infered_type = $this->infer_functional_operation($node);
			// 	break;
			case DictExpression::KIND:
				$infered_type = $this->infer_dict_expression($node);
				break;
			case ArrayExpression::KIND:
				$infered_type = $this->infer_array_expression($node);
				break;
			case LambdaExpression::KIND:
				$infered_type = $this->infer_lambda_expression($node);
				break;
			// case NamespaceIdentifier::KIND:
			// 	$infered_type = $this->infer_namespace_identifier($node);
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
			case ReferenceOperation::KIND:
				$infered_type = $this->infer_expression($node->identifier);
				break;
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
				throw $this->new_syntax_error("Index type for Array should be 'UInt', '{$right_type->name}' supplied.", $node);
			}
		}
		elseif ($left_type instanceof DictType) {
			if (TypeFactory::is_dict_key_directly_supported_type($right_type)) {
				// okay
			}
			elseif (TypeFactory::is_dict_key_castable_type($right_type)) {
				// 一些类型值作为下标调用时，PHP中有一些隐式的转换规则，这些规则往往不那么清晰，容易出问题，故我们需要显示的转换
				// 如false将转换为0，但实际情况可能需要的是''
				// 而float如0.1将转换为0，但实际情况可能需要的是'0.1'
				$node->right->infered_type = $right_type;
			}
			else {
				throw $this->new_syntax_error("Invalid key type '{$right_type->name}' for Dict.", $node->right);
			}
		}
		elseif ($left_type instanceof AnyType) {
			// 仅允许将实际类型为Dict的用于这类情况
			// 由于PHP、JS中字符串也可以使用[index]访问，而它们的多字节字符处理方式不同
			// 限制key的类型为字符串，这样可以避免被用于对字符串进行[index]方式访问
			if ($right_type !== TypeFactory::$_string) {
				throw $this->new_syntax_error("Key type for Dict should only be 'String', type '{$right_type->name}' applied.", $node);
			}
		}
		// 由于PHP、JS中字符串的多字节字符处理方式不同，按索引访问会导致语义不一致
		// elseif ($left_type instanceof StringType) {
		// 	return TypeFactory::$_string;
		// }
		else {
			throw $this->new_syntax_error("Cannot use key accessing for type '{$left_type->name}'.", $node);
		}

		// the value type
		return $left_type->value_type ?? TypeFactory::$_any;
	}

	private function infer_binary_operation(BinaryOperation $node): IType
	{
		$left_type = $this->infer_expression($node->left);
		$right_type = $this->infer_expression($node->right);
		$operator = $node->operator;

		if (OperatorFactory::is_number_operator($operator)) {
			if (!TypeFactory::is_number_type($left_type) || !TypeFactory::is_number_type($right_type)) {
				throw $this->new_syntax_error("Math operation cannot use for non Int/UInt/Float type values.", $node);
			}

			if ($left_type === TypeFactory::$_float || $right_type === TypeFactory::$_float) {
				return TypeFactory::$_float;
			}

			if ($left_type === TypeFactory::$_int || $right_type === TypeFactory::$_int) {
				return TypeFactory::$_int;
			}

			return TypeFactory::$_uint;
		}

		if (OperatorFactory::is_bool_operator($operator)) {
			return TypeFactory::$_bool;
		}

		if ($operator === OperatorFactory::$_none_coalescing) {
			return $this->reduce_types([$left_type, $right_type], $node);
		}

		// string or array
		if ($operator === OperatorFactory::$_concat) {
			if ($left_type instanceof ArrayType) {
				$node->is_array_concat = true;
				$this->assert_type_compatible($left_type, $right_type, $node->right);
				return $left_type;
			}
			elseif ($left_type instanceof DictType) {
				throw $this->new_syntax_error("'concat' operation cannot use for Dict type values.", $node);
			}
			else {
				return TypeFactory::$_string;
			}
		}

		// array or dict
		if ($operator === OperatorFactory::$_merge) {
			if (!$left_type instanceof ArrayType && !$left_type instanceof DictType) {
				throw $this->new_syntax_error("'merge' operation just support Array/Dict type values.", $node);
			}

			$this->assert_type_compatible($left_type, $right_type, $node->right);
			return $left_type;
		}

		if (OperatorFactory::is_bitwise_operator($operator)) {
			return $this->reduce_types([$left_type, $right_type], $node);
		}

		throw $this->new_syntax_error("Unknow operator: '{$node->operator->sign}'", $node);
	}

	private function infer_as_operation(AsOperation $node): IType
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
		$this->infer_expression($node->right);

		$cast_type = $node->right;

		if (!$cast_type instanceof IType) {
			throw $this->new_syntax_error("Invalid 'is' expression '{$node->right->name}'.", $node);
		}

		return $cast_type;
	}

	// function infer_functional_operation(FunctionalOperation $node): IType
	// {
	// 	$has_float = false;
	// 	foreach ($node->arguments as $argument) {
	// 		$infered_type = $this->infer_expression($argument);
	// 		if ($infered_type === TypeFactory::$_float) {
	// 			$has_float = true;
	// 		}
	// 	}

	// 	if (OperatorFactory::is_string_operator($node->operator)) {
	// 		return TypeFactory::$_string;
	// 	}
	// 	elseif (OperatorFactory::is_number_operator($node->operator)) {
	// 		return $has_float ? TypeFactory::$_float : TypeFactory::$_int;
	// 	}
	// 	elseif (OperatorFactory::is_bool_operator($node->operator)) {
	// 		return TypeFactory::$_bool;
	// 	}
	// 	else {
	// 		throw $this->new_syntax_error("Unknow operator: '{$node->operator->sign}'", $node);
	// 	}
	// }

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
		elseif ($node->operator === OperatorFactory::$_reference) {
			return $infered_type;
		}
		elseif ($node->operator === OperatorFactory::$_bitwise_not) {
			return $infered_type === TypeFactory::$_uint || $infered_type === TypeFactory::$_int || $infered_type === TypeFactory::$_float
				? TypeFactory::$_int
				: $infered_type;
		}
		else {
			throw $this->new_syntax_error("Unknow operator: '{$node->operator->sign}'", $node);
		}
	}

	private function infer_conditional_expression(ConditionalExpression $node): IType
	{
		$testing_type = $this->infer_expression($node->test);

		if ($node->then === null) {
			$then_type = $testing_type;
		}
		else {
			$then_type = $this->infer_expression($node->then);
		}

		$else_type = $this->infer_expression($node->else);

		return $this->reduce_types([$then_type, $else_type], $node);
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

		$value_type = $this->reduce_types($infered_value_types, $node);

		return TypeFactory::create_array_type($value_type);
	}

	private function infer_dict_expression(DictExpression $node): IType
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
			elseif (TypeFactory::is_dict_key_castable_type($key_type)) {
				// need cast
				$item->key->infered_type = $key_type;
			}
			else {
				throw $this->new_syntax_error("Invalid key type for Dict.", $item->key);
			}

 			$infered_value_types[] = $this->infer_expression($item->value);
		}

		$value_type = $this->reduce_types($infered_value_types, $node);

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
			// for IterableType and MetaType
			if (isset($type->value_type)) {
				// check the value type
				$this->check_type($type->value_type);
			}
			elseif ($type instanceof CallableType) {
				$this->check_callable_type($type);
			}

			// no any other need to check
		}
		elseif ($type instanceof PlainIdentifier) {
			$infered_type = $this->infer_plain_identifier($type);
			if (!$type->symbol->declaration instanceof ClassLikeDeclaration) {
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
		$node->checked = true;

		if ($node->type) {
			$this->check_type($node->type);
		}
		else {
			$node->type = TypeFactory::$_void;
		}

		$node->parameters and $this->check_parameters_for_callable_declaration($node);
	}

	private function infer_classlike_identifier(ClassLikeIdentifier $node): IType
	{
		$declaration = $this->require_classlike_declaration($node);
		return $declaration->type;
	}

	private function infer_constant(ConstantIdentifier $node): IType
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
		if (!$target_main) {
			return null;
		}

		// check all expect variables is decalared in current including place
		foreach ($target_main->parameters as $parameter) {
			$param_name = $parameter->name;
			$symbol = $node->symbols[$param_name] ?? $this->find_symbol_by_name($param_name);
			if ($symbol === null) {
				throw $including->parser->new_ast_check_error("Expected var '{$param_name}' to #include({$node->target}).", $node, 1);
				// throw $including_unit->get_checker()->new_syntax_error("Expect var '{$param_name}' for #include({$node->target}) not found in program file '{$this->program->name}'.", $node);
			}
		}

		return $target_main->type;
	}

	protected function require_program_declaration(string $name, Node $ref_node)
	{
		$program = $this->unit->programs[$name] ?? null;
		if (!$program) {
			throw $this->new_syntax_error("'{$node->target}' not found.", $ref_node);
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

		// if ($declar->type === TypeFactory::$_class && $callee->symbol->declaration instanceof ClassDeclaration) {
		if ($callee->symbol->declaration instanceof ClassLikeDeclaration) {
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

		// if is a variable, use it's type decalartion
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

			if ($parameter->is_referenced) {
				if (!ASTHelper::is_assignable_expression($argument)) {
					throw $this->new_syntax_error("Argument $key is invalid for the referenced parameter defined in '{$src_callee_declar->name}'.", $argument);
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
			// $last_idx = array_key_last($normalizeds); // array_key_last do not support in PHP 7.2
			$idx_list = array_keys($normalizeds);
			$last_idx = end($idx_list);

			$i = 0;
			foreach ($parameters as $parameter) {
				if ($i <= $last_idx && !isset($normalizeds[$i])) {
					$normalizeds[$i] = $parameter->value;
				}

				$i++;
			}
		}

		// if ($node->callbacks) {
		// 	$used_arg_names = true;
		// 	$this->check_call_callbacks($node, $callee_declar, $parameters, $normalizeds);
		// }

		if ($used_arg_names) {
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

	// private function check_call_callbacks(CallExpression $node, ICallableDeclaration $callee_declar, array $parameters, array &$normalizeds)
	// {
	// 	if (!$callee_declar->callbacks) {
	// 		throw $this->new_syntax_error("Callback arguments has setted, but not found any callback-protocols in declaration of '{$src_callee_declar->name}'.", $node);
	// 	}

	// 	// check and fill all rest and default parameter values
	// 	// because of callback arguments would append at end
	// 	$normalizeds_count = count($normalizeds);
	// 	$parameters_count = count($parameters);
	// 	if ($normalizeds_count < $parameters_count) {
	// 		for ($i = $normalizeds_count; $i < $parameters_count; $i++) {
	// 			$normalizeds[$i] = $parameters[$i]->value;
	// 		}
	// 	}

	// 	// set to default when not set a name
	// 	if (count($node->callbacks) === 1 && $node->callbacks[0]->name === null) {
	// 		$node->callbacks[0]->name = $callee_declar->callbacks[0]->name;
	// 	}

	// 	foreach ($node->callbacks as $cb) {
	// 		$infered_type = $this->infer_callback_argument($cb);

	// 		list($idx, $protocol) = $this->require_callback_protocol_by_name($cb->name, $callee_declar->callbacks, $node->callee);

	// 		if ($infered_type === null) {
	// 			if ($protocol->type !== null && $protocol->type !== TypeFactory::$_void) {
	// 				$protocol_type_name = self::get_type_name($protocol->type);
	// 				throw $this->new_syntax_error("Type invalid. The supplied callback return type is 'Void', required of '{$protocol->name}' is '{$protocol_type_name}'.", $cb);
	// 			}
	// 		}
	// 		elseif ($protocol->type === null) {
	// 			$infered_type_name = self::get_type_name($infered_type);
	// 			throw $this->new_syntax_error("Type invalid. The supplied callback return type is '{$infered_type_name}', required of '{$protocol->name}' is 'Void'.", $cb);
	// 		}
	// 		elseif (!$protocol->type->is_accept_type($infered_type)) {
	// 			$infered_type_name = self::get_type_name($infered_type);
	// 			$protocol_type_name = self::get_type_name($protocol->type);
	// 			throw $this->new_syntax_error("Type invalid. The supplied callback return type is '{$infered_type_name}', required of '{$protocol->name}' is '{$protocol_type_name}'.", $cb);
	// 		}

	// 		$normalizeds[$parameters_count + $idx] = $cb;
	// 	}
	// }

	private function assert_type_compatible(IType $left, IType $right, Node $value_node)
	{
		if (!$left->is_accept_type($right)) {
			// for [] / [:]
			if (($value_node instanceof ArrayLiteral || $value_node instanceof DictLiteral) && !$value_node->items) {
				return;
			}

			$left_type_name = self::get_type_name($left);
			$right_type_name = self::get_type_name($right);
			throw $this->new_syntax_error("Types not compatible with '{$left_type_name}' and '{$right_type_name}'.", $value_node);
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

		if ($declaration instanceof VariableDeclaration || $declaration instanceof ConstantDeclaration || $declaration instanceof ParameterDeclaration || $declaration instanceof ClassLikeDeclaration) {
			if (!$declaration->type) {
				// 这类声明跟顺序相关，前面声明的用到后面的则当作未定义
				// 不过在不同程序文件中的常量是个问题，需要后续优化
				throw $this->new_syntax_error("Declaration of '{$node->name}' not found.", $node);
			}

			$type = $declaration->type;
		}
		elseif ($declaration instanceof ICallableDeclaration) {
			// if ($declaration instanceof ClassDeclaration) {
			// 	$type = $declaration->type;
			// }
			// else {
				$type = TypeFactory::$_callable;
			// }
		}
		elseif ($declaration instanceof NamespaceDeclaration) {
			$type = TypeFactory::$_namespace;
		}
		else {
			throw new UnexpectNode($declaration);
		}

		return $type;
	}

	private function infer_accessing_identifier(AccessingIdentifier $node): ?IType
	{
		$member = $this->require_accessing_identifier_declaration($node);
		switch ($member::KIND) {
			case FunctionBlock::KIND:
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

			// case ClassDeclaration::KIND:
			// 	return TypeFactory::$_class;

			case NamespaceDeclaration::KIND:
				return TypeFactory::$_namespace;

			default:
				throw new UnexpectNode($member);
		}
	}

	private function infer_regular_expression(RegularExpression $node): IType
	{
		return TypeFactory::$_regex;
	}

	// function infer_relay_expression(RelayExpression $node): IType
	// {
	// 	$this->infer_expression($node->argument);

	// 	$infered_type = null;
	// 	foreach ($node->callees as $callee) {
	// 		$infered_type = $this->require_callee_declaration($callee)->type;
	// 	}

	// 	return $infered_type;
	// }

	private function infer_escaped_string_interpolation(EscapedStringInterpolation $node): IType
	{
		foreach ($node->items as $item) {
			if (is_object($item) && !$item instanceof ILiteral) {
				$this->infer_expression($item);
			}
		}

		return TypeFactory::$_string;
	}

	private function infer_variable(VariableIdentifier $node): IType
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
		if ($node->name instanceof IExpression) {
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
		$symbol = $this->get_symbol_in_program($this->program, $name);

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

		// if ($this->block === null) {
		// 	$symbol = $this->get_symbol_in_program($this->program, $name);
		// }
		// else {
		// 	// find in block
		// 	$block = $this->block;

		// 	if (isset($block->symbols[$name])) {
		// 		$symbol = $block->symbols[$name];
		// 	}
		// 	else {
		// 		while (isset($block->super_block)) {
		// 			$block = $block->super_block;
		// 			if (isset($block->symbols[$name])) {
		// 				$symbol = $block->symbols[$name];
		// 				break;
		// 			}
		// 		}
		// 	}

		// 	if (!isset($symbol)) {
		// 		$symbol = $this->get_symbol_in_program($block->program, $name);
		// 	}
		// }

		if ($symbol) {
			if ($symbol->declaration instanceof UseDeclaration) {
				$symbol->declaration = $this->require_declaration_for_use($symbol->declaration);
			}
			elseif (!$symbol->declaration->checked) {
				$this->check_declaration($symbol->declaration);
			}
		}

		// find in unit level symbols
		return $symbol;
	}

	private function get_symbol_in_program(Program $program, string $name)
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
				if ($declar instanceof VariableDeclaration) {
					$type = self::get_type_name($declar->type);
					$item = "$type variable '$node->name'";
				}
				elseif ($declar instanceof ConstantDeclaration) {
					$item = "constant '$node->name'";
				}
				else {
					$item = "'$node->name'";
				}

				throw $this->new_syntax_error("Cannot use $item as a callable.", $node);
			}
		}
		elseif ($node instanceof ClassIdentifier) {
			$declar = $this->require_classlike_declaration($node);
		}
		else {
			$kind = $node::KIND;
			throw $this->new_syntax_error("Unknow callee kind: '$kind'.", $node);
		}

		return $declar;
	}

	private function check_callable_declaration(ICallableDeclaration $node)
	{
		if ($node->checking) {
			throw $this->new_syntax_error("Function '{$node->name}' has a circular checking, needs a return type.", $node);
		}

		$node->checking = true;

		switch ($node::KIND) {
			case FunctionBlock::KIND:
				$this->check_function_block($node);
				break;

			case LambdaExpression::KIND:
				$this->infer_lambda_expression($node);
				break;

			case FunctionDeclaration::KIND:
				$this->check_function_declaration($node);
				break;

			case CallbackProtocol::KIND:
				break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unexpect callable declaration kind: '{$kind}'.", $node);
		}
	}

	private function require_function_declaration(PlainIdentifier $node): IFunctionDeclaration
	{
		if (!$node->symbol) {
			if (!isset($this->unit->symbols[$node->name])) {
				throw $this->new_syntax_error("Symbol '{$node->name}' not found.", $node);
			}

			$node->symbol = $this->unit->symbols[$node->name];

			$this->check_function($node->symbol->declaration);
		}

		return $node->symbol->declaration;
	}

	private function require_accessing_identifier_declaration(AccessingIdentifier $node): IMemberDeclaration
	{
		if (!$node->symbol) {
			$master = $node->master;
			$infered_type = $this->infer_expression($master);

			if ($infered_type === TypeFactory::$_any) {
				// let member type to Any on master is Any
				$this->create_any_symbol_for_accessing_identifier($node);
			}
			elseif ($infered_type === TypeFactory::$_namespace) {
				dump($master);
				throw new Exception('whats the problem!!!');
				$this->attach_namespace_member_symbol($master->symbol->declaration, $node);
			}
			// elseif ($infered_type === TypeFactory::$_class) {
			elseif ($infered_type instanceof MetaType) { // includes static call for class members
				$node->symbol = $this->require_class_member_symbol($master->symbol->declaration, $node);
				if (!$node->symbol->declaration->is_static) {
					$name = $this->get_declaration_name($node->symbol->declaration);
					throw $this->new_syntax_error("Invalid to accessing a non-static member '{$name}'", $node);
				}
			}
			elseif ($infered_type !== null) { // the master would be an object expression
				$node->symbol = $this->require_class_member_symbol($infered_type->symbol->declaration, $node);
				$node->symbol->declaration->type || $this->check_class_member_declaration($node->symbol->declaration);
			}
			else {
				throw new UnexpectNode($master);
			}
		}

		return $node->symbol->declaration;
	}

	private function create_any_symbol_for_accessing_identifier(AccessingIdentifier $node)
	{
		$node->symbol = new Symbol(ASTFactory::$virtual_property_for_any, $node->name);
	}

	private function find_member_symbol_in_class(ClassLikeDeclaration $classlike, string $member_name): ?Symbol
	{
		// 当作为外部调用时，应命中这里的逻辑
		$declaration = $classlike->actual_members[$member_name] ?? null;
		if ($declaration) {
			return $declaration->symbol;
		}

		// 当调用的地方为正在检查的类中时，需要走以下逻辑

		// find in self
		$declaration = $classlike->members[$member_name] ?? null;
		if ($declaration) {
			if (!$declaration->checked) {
				// switch to target program
				$temp_program = $this->program;
				$this->program = $classlike->program;

				$this->check_class_member_declaration($declaration);

				// switch back
				$this->program = $temp_program;
			}

			// return $symbol;
			return $declaration->symbol;
		}

		// find in extends class
		if ($classlike->inherits) {
			$symbol = $this->find_member_symbol_in_class($classlike->inherits->symbol->declaration, $member_name);
			if ($symbol) {
				return $symbol;
			}
		}

		// find in implements interfaces
		if ($classlike->baseds) {
			foreach ($classlike->baseds as $interface) {
				if (!$interface->symbol) {
					$this->require_classlike_declaration($interface);
				}

				$symbol = $this->find_member_symbol_in_class($interface->symbol->declaration, $member_name);
				if ($symbol) {
					return $symbol;
				}
			}
		}

		return null;
	}

	private function require_class_member_symbol(ClassLikeDeclaration $classlike, Identifiable $node): Symbol
	{
		$symbol = $this->find_member_symbol_in_class($classlike, $node->name);
		if (!$symbol) {
			throw $this->new_syntax_error("Member '{$node->name}' not found in '{$classlike->name}'", $node);
		}

		return $symbol;
	}

	private function attach_namespace_member_symbol(NamespaceDeclaration $ns_declaration, AccessingIdentifier $node)
	{
		$node->symbol = $ns_declaration->symbols[$node->name] ?? null;
		if (!$node->symbol) {
			throw $this->new_syntax_error("Symbol '{$node->name}' not found in '{$ns_declaration->name}'.", $node);
		}

		return $node->symbol;
	}

	// includes builtin types, and classes, and namespace
	private function require_object_declaration(IExpression $node): ClassLikeDeclaration
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

	private function require_classlike_declaration(PlainIdentifier $identifier)
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
					$symbol->declaration = $this->require_declaration_for_use($symbol->declaration);
				}
			}

			if (!$symbol->declaration instanceof ClassLikeDeclaration) {
				throw $this->new_syntax_error("Declaration of '{$identifier->name}' not a classlike declaration.", $identifier);
			}

			$identifier->symbol = $symbol;
		}

		$this->check_classlike_declaration($symbol->declaration);

		return $symbol->declaration;
	}

	private function require_global_symbol_for_identifier(Identifiable $identifier)
	{
		$symbol = $this->program->symbols[$identifier->name] ?? $this->program->unit->symbols[$identifier->name] ?? null;
		if ($symbol === null) {
			throw $this->new_syntax_error("Symbol '{$identifier->name}' not found.", $identifier);
		}

		return $symbol;
	}

	private function require_program_of_declaration(IDeclaration $declaration): Program
	{
		// just a local variable
		if (!isset($declaration->program) && !isset($declaration->super_block)) {
			return $this->program;
		}

		while (!isset($declaration->program)) {
			$declaration = $declaration->super_block;
		}

		return $declaration->program;
	}

	private function require_symbol_for_namespace(NamespaceIdentifier $ns, string $name)
	{
		$symbol = $this->require_unit($ns)->symbols[$name] ?? null;
		if ($symbol === null) {
			throw $this->new_syntax_error("Target '{$ns->uri}.{$name}' for use not found in unit '{$ns->uri}'.", $ns);
		}

		return $symbol;
	}

	private function require_declaration_for_use(UseDeclaration $use): IRootDeclaration
	{
		if ($use->source_declaration === null) {
			// find from the target Unit
			$name = $use->source_name ?? $use->name;
			$symbol = $this->require_unit($use->ns)->symbols[$name] ?? null;
			if ($symbol === null) {
				throw $this->new_syntax_error("Target '{$name}' for use not found in unit '{$use->ns->uri}'.", $use);
			}

			$target = $symbol->declaration;
			if (!$target->checked) {
				$target->program->unit->get_checker()->check_declaration($target);
			}

			$use->source_declaration = $target;
		}

		return $use->source_declaration;
	}

	private function require_unit(NamespaceIdentifier $ns): Unit
	{
		// the __public.th

		$ns_uri = $ns->uri;
		$program = $this->unit->use_units[$ns_uri] ?? null;
		if (!$program) {
			throw $this->new_syntax_error("Unit '{$ns_uri}' not found.", $ns);
		}

		return $program;
	}

	private function reduce_types(array $types, Node $node): ?IType
	{
		if (!$types) return null;

		$count = count($types);

		for ($i = 0; $i < $count; $i++) {
			$result_type = $types[$i];
			if ($result_type !== null) {
				break;
			}
		}

		for ($i = $i + 1; $i < $count; $i++) {
			$type = $types[$i];
			if ($type === null || $type === $result_type || $type === TypeFactory::$_none) {
				continue;
			}

			if ($type instanceof BaseType) {
				if ($result_type->is_accept_type($type)) {
					//
				}
				elseif ($type->is_accept_type($result_type)) {
					$result_type = $type;
				}
				else {
					$result_type = TypeFactory::$_any;
				}

				continue;
			}

			if ($type->symbol !== $result_type->symbol) {
				if ($type->is_based_with($result_type)) {
					// do nothing
				}
				elseif ($type->is_based_with($result_type)) {
					$result_type = $type;
				}
				else {
					$result_type = TypeFactory::$_any;
				}
			}
		}

		return $result_type;
	}

	protected function new_syntax_error($message, $node = null)
	{
		return $this->program->parser->new_ast_check_error($message, $node, 1);
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
