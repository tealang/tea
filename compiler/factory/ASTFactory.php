<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ASTFactory
{
	// for #default label
	public static $default_value_mark;

	// for properties of Any type object in AccessingIdentifer
	// public static $virtual_property_for_any;

	public $root_namespace;

	public $parser;

	private $ns;

	private $unit;

	private $program;

	/**
	 * @var IDeclaration
	 */
	private $declaration;

	/**
	 * current classkindred
	 * @var ClassKindredDeclaration
	 */
	private $class;

	/**
	 * current function or closure
	 * @var IScopeBlock
	 */
	private $scope;

	/**
	 * @var IBlock
	 */
	private $block;

	private $unit_path_symbol;

	public static function init_ast_system()
	{
		TypeFactory::init();
		OperatorFactory::init();

		self::$default_value_mark = new LiteralDefaultMark();
	}

	public function __construct(Unit $unit)
	{
		$unit->factory = $this;
		$this->unit = $unit;
		$this->root_namespace = $this->create_namespace_identifier(['']);

		// the constant 'UNIT_PATH'
		$decl = new ConstantDeclaration(_PUBLIC, _UNIT_PATH, TypeFactory::$_string, null);
		$this->unit_path_symbol = new Symbol($decl);
	}

	public function set_as_main()
	{
		$this->program->as_main = true;
		$this->unit->as_main = true;
	}

	public function set_namespace(NamespaceIdentifier $ns)
	{
		if ($this->unit->ns) {
			throw $this->parser->new_parse_error("Cannot redeclare module namespace");
		}

		$this->ns = $ns;
		$this->unit->ns = $ns;
		$this->unit->name = $ns->uri;
	}

	public function set_unit_option(string $key, string $value)
	{
		$this->unit->{$key} = $value;
	}

	public function exists_use_unit(NamespaceIdentifier $ns)
	{
		return isset($this->unit->use_units[$ns->uri]);
	}

	public function create_use_statement(NamespaceIdentifier $ns, array $targets)
	{
		// add to module uses
		$this->unit->use_units[$ns->uri] = $ns;

		$statement = new UseStatement($ns, $targets);
		$this->program->uses[] = $statement;

		// add namespace it self as a target when not has any targets
		if (!$targets) {
			$use = $this->append_use_target($ns);
		}

		return $statement;
	}

	public function append_use_target(NamespaceIdentifier $ns, string $target_name = null, string $source_name = null)
	{
		$decl = new UseDeclaration($ns, $target_name, $source_name);
		$this->parser->attach_position($decl);

		if ($this->parser->is_parsing_header) {
			$this->create_internal_symbol($decl);
		}
		else {
			$this->create_program_symbol($decl);
		}

		// need to check
		$this->program->use_targets[] = $decl;

		return $decl;
	}

	public function create_break_statement(?BaseExpression $argument = null)
	{
		return new BreakStatement($argument, $this->block);
	}

	public function create_continue_statement(?BaseExpression $argument = null)
	{
		return new ContinueStatement($argument, $this->block);
	}

	public function create_return_statement(?BaseExpression $argument)
	{
		return new ReturnStatement($argument, $this->block);
	}

	public function create_throw_statement(?BaseExpression $argument)
	{
		return new ThrowStatement($argument, $this->block);
	}

	public function create_exit_statement(?BaseExpression $argument)
	{
		return new ExitStatement($argument, $this->block);
	}

	public function create_accessing_identifier(BaseExpression $basing, string $name, bool $nullsafe = false)
	{
		return new AccessingIdentifier($basing, $name, $nullsafe);
	}

	public function create_classkindred_identifier(string $name)
	{
		$identifier = new ClassKindredIdentifier($name);
		$this->set_defer_check($identifier);
		return $identifier;
	}

	public function create_variable_identifier(string $name)
	{
		if ($name === '$this') {
			$identifier = $this->create_builtin_identifier(_THIS);
		}
		else {
			$identifier = new VariableIdentifier($name);
			$this->set_defer_check($identifier);
		}

		return $identifier;
	}

	public function create_identifier(string $name)
	{
		$identifier = TeaHelper::is_builtin_identifier($name)
			? $this->create_builtin_identifier($name)
			: $this->create_plain_identifier($name);

		return $identifier;
	}

	public function create_plain_identifier(string $name)
	{
		$identifier = new PlainIdentifier($name);
		$this->set_defer_check($identifier);
		return $identifier;
	}

	public function create_builtin_identifier(string $token): BaseExpression
	{
		switch ($token) {
			case _THIS:
				$identifier = new PlainIdentifier($token);
				if ($this->class) {
					// function/const/property declaration
					$decl = $this->declaration;
					$identifier->symbol = $decl->is_static
						? $this->class->this_class_symbol
						: $this->class->this_object_symbol;
				}
				elseif (!$this->attach_local_symbol($identifier)) { // it would be has an #expect
					throw $this->parser->new_parse_error("Identifier '$token' not defined");
				}

				break;
			case _SUPER:
				$identifier = new PlainIdentifier($token);
				if (!$this->class) {
					throw $this->parser->new_parse_error("Identifier '$token' required use in class context");
				}
				break;
			case _VAL_NONE:
				$identifier = new LiteralNone();
				break;
			case _VAL_TRUE:
				$identifier = new LiteralBoolean(true);
				break;
			case _VAL_FALSE:
				$identifier = new LiteralBoolean(false);
				break;
			// case _UNIT_PATH:
			// 	$identifier = new ConstantIdentifier(_UNIT_PATH);
			// 	$identifier->symbol = $this->unit_path_symbol;
			// 	break;
			default:
				throw $this->parser->new_parse_error("Unknow builtin identifier '$token'");
		}

		return $identifier;
	}

	public function create_namespace_identifier(array $names)
	{
		$ns = new NamespaceIdentifier($names);
		return $ns;
	}

	// public function create_include_expression(string $target)
	// {
	// 	$expression = new IncludeExpression($target);

	// 	// prepare for check #expect of target program
	// 	$expression->symbols = $this->collect_created_symbols_in_current_function();

	// 	return $expression;
	// }

	public function create_yield_expression(BaseExpression $argument)
	{
		if ($this->declaration instanceof IFunctionDeclaration) {
			$this->declaration->declared_type = TypeFactory::$_generator;
		}

		// force set type Generator to current function
		// $this->function->declared_type = TypeFactory::$_generator;

		return new YieldExpression($argument);
	}

	private function set_defer_check(Identifiable $identifier)
	{
		if (!$this->declaration instanceof IFunctionDeclaration or !$this->attach_local_symbol($identifier)) {
			$this->declaration->append_unknow_identifier($identifier);
		}
	}

	public function remove_defer_check(PlainIdentifier $identifier)
	{
		$block = $this->scope;
		$block->remove_unknow_identifier($identifier);

		while ($block = $block->belong_block) {
			if ($block instanceof IScopeBlock) {
				$block->remove_unknow_identifier($identifier);
			}
		}
	}

	public function create_prefix_operation(BaseExpression $expr, Operator $operator)
	{
		$expr = new PrefixOperation($expr, $operator);
		return $expr;
	}

	public function create_postfix_operation(BaseExpression $expr, Operator $operator)
	{
		$expr = new PostfixOperation($expr, $operator);
		return $expr;
	}

	public function create_binary_operation(BaseExpression $left, BaseExpression $right, Operator $operator)
	{
		$expr = new BinaryOperation($left, $right, $operator);
		return $expr;
	}

	public function create_assignment(BaseExpression $assigned_to, BaseExpression $value, Operator $operator)
	{
		if ($assigned_to instanceof PlainIdentifier) {
			$this->process_assigned_target($assigned_to, $value);
		}
		elseif ($assigned_to instanceof Destructuring) {
			// pass
		}
		elseif (ASTHelper::is_pure_bracket_accessing_expr($assigned_to)) {
			$basing = $assigned_to->get_final_basing();
			$this->process_assigned_target($basing);
		}

		$assignment = new AssignmentOperation($assigned_to, $value, $operator);

		return $assignment;
	}

	public function create_destructuring(array $items)
	{
		foreach ($items as $item) {
			if ($item instanceof PlainIdentifier) {
				$this->process_assigned_target($item);
			}
			elseif ($item instanceof DictMember && $item->value instanceof PlainIdentifier) {
				$this->process_assigned_target($item->value);
			}
			else {
				// pass
			}
		}

		$expr = new Destructuring($items);
		return $expr;
	}

	private function process_assigned_target(PlainIdentifier $identifier, ?BaseExpression $value = null)
	{
		if ($identifier->symbol) {
			// no any
		}
		else {
			// symbol has not declared
			$this->auto_declare_for_assigning_identifier($identifier, $value);

			// remove from check list
			$this->remove_defer_check($identifier);
		}
	}

	private function auto_declare_for_assigning_identifier(BaseExpression $identifier, ?BaseExpression $value)
	{
		// if (!TeaHelper::is_normal_variable_name($identifier->name)) {
		// 	throw $this->parser->new_parse_error("Identifier '$identifier->name' not a valid variable name");
		// }

		$decl = new VariableDeclaration($identifier->name, null, $value);
		$decl->is_virtual = true;
		$decl->block = $this->block;

		// link to symbol
		$identifier->symbol = $this->create_local_symbol($decl);
	}

// ---

	public function create_program(string $file, BaseParser $parser)
	{
		$this->parser = $parser;

		$program = new Program($file, $this->unit);
		$program->parser = $parser;

		// check name is in used
		if (isset($this->unit->programs[$program->name])) {
			throw new Exception("Error: Program name '{$program->name}' has been used, please rename the file '{$program->file}'");
		}

		$program->initializer = new FunctionDeclaration(_INTERNAL, '__main', null, []);
		$program->initializer->program = $program;

		$this->program = $program;
		$this->unit->programs[$program->name] = $program;

		$this->switch_to_initializer();

		return $program;
	}

	// public function create_virtual_variable(string $name, ?IType $type = null, ?BaseExpression $value = null)
	// {
	// 	$decl = new VariableDeclaration($name, $type, $value);
	// 	$symbol = new Symbol($decl);
	// 	return [$decl, $symbol];
	// }

	public function create_virtual_constant(string $name, ?IType $type = null, ?BaseExpression $value = null)
	{
		if ($type === null && $value === null) {
			$type = TypeFactory::$_any;
		}

		$decl = new ConstantDeclaration(_INTERNAL, $name, $type, $value);
		$decl->is_virtual = true;
		$symbol = new Symbol($decl);
		return [$decl, $symbol];
	}

	public function create_virtual_function(string $name, Program $program = null)
	{
		// $program and $program = $this->switch_program($program);

		$decl = new FunctionDeclaration(_INTERNAL, $name, null, []);
		$decl->is_virtual = true;

		// $symbol = $this->create_symbol_for_top_declaration($decl, null);
		$symbol = new Symbol($decl);

		// $program and $program = $this->switch_program($program);

		return [$decl, $symbol];
	}

	// private function switch_program(Program $program)
	// {
	// 	$temp = $this->program;
	// 	$this->program = $program;
	// 	return $temp;
	// }

	public function create_virtual_class(string $name, Program $program = null)
	{
		// $program and $program = $this->switch_program($program);

		$decl = new ClassDeclaration(null, 'Object');
		$decl->is_virtual = true;

		// $symbol = $this->create_symbol_for_top_declaration($decl, null);
		$symbol = new Symbol($decl);

		self::bind_class_symbol($decl, $symbol);

		// $program and $program = $this->switch_program($program);

		return [$decl, $symbol];
	}

	public function create_virtual_method(string $name, ClassKindredDeclaration $class)
	{
		$decl = new MethodDeclaration(null, $name);
		$decl->is_virtual = true;
		$decl->infered_type = TypeFactory::$_any;
		$decl->parameters = [];

		$symbol = new Symbol($decl);
		$class->append_member_symbol($symbol);

		return [$decl, $symbol];
	}

	public function create_virtual_property(string $name, ClassKindredDeclaration $class)
	{
		$decl = new PropertyDeclaration(null, $name, TypeFactory::$_any);
		$decl->is_virtual = true;
		$decl->infered_type = TypeFactory::$_any;

		$symbol = new Symbol($decl);
		$class->append_member_symbol($symbol);

		return [$decl, $symbol];
	}

	public function create_virtual_class_constant(string $name, ?IType $type, ClassKindredDeclaration $class)
	{
		if ($type === null) {
			$type = TypeFactory::$_any;
		}

		$decl = new ClassConstantDeclaration(null, $name, $type);
		$decl->is_virtual = true;
		$decl->infered_type = $type;

		$symbol = new Symbol($decl);
		$class->append_member_symbol($symbol);

		return [$decl, $symbol];
	}

	public function create_object_member(?string $quote_mark, string $name)
	{
		$decl = new ObjectMember($name, $quote_mark);
		$symbol = new Symbol($decl);

		return [$decl, $symbol];
	}

	public function create_builtin_type_class_declaration(string $name)
	{
		$type_identifier = TypeFactory::get_type($name);
		if ($type_identifier === null) {
			return null;
		}

		$decl = new BuiltinTypeClassDeclaration(_PUBLIC, $name);

		$symbol = $this->create_internal_symbol($decl);
		$this->bind_class_symbol($decl, $symbol);

		// bind to type
		$type_identifier->symbol = $symbol;

		$this->begin_class($decl);

		return $decl;
	}

	public function create_class_declaration(string $name, string $modifier, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'class');

		$decl = new ClassDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);
		$this->bind_class_symbol($decl, $symbol);

		$this->begin_class($decl);

		return $decl;
	}

	public function create_interface_declaration(string $name, string $modifier, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'interface');

		$decl = new InterfaceDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);
		$this->bind_class_symbol($decl, $symbol);

		$this->begin_class($decl);

		return $decl;
	}

	public function create_intertrait_declaration(string $name, string $modifier, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'intertrait');

		$decl = new IntertraitDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);
		$this->bind_class_symbol($decl, $symbol);

		$this->begin_class($decl);

		return $decl;
	}

	public function create_trait_declaration(string $name, string $modifier, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'trait');

		$decl = new TraitDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);
		$this->bind_class_symbol($decl, $symbol);

		$this->begin_class($decl);

		return $decl;
	}

	private static function bind_class_symbol(ClassKindredDeclaration $decl, Symbol $symbol)
	{
		// identifier for 'this'
		$identifier = new ClassKindredIdentifier($decl->name); // as a Type for 'this'
		$identifier->symbol = $symbol;

		$decl->typing_identifier = $identifier;
		$decl->this_class_symbol = $symbol;
		$decl->this_object_symbol = self::create_symbol_this($identifier);

		// the MetaType
		$decl->declared_type = TypeFactory::create_meta_type($identifier);
	}

	private static function create_symbol_this(ClassKindredIdentifier $class)
	{
		$decl = new FinalVariableDeclaration(_THIS, $class);
		$decl->is_checked = true; // do not need to check
		return new Symbol($decl);
	}

	public function create_traits_using_statement(array $items)
	{
		$decl = new TraitsUsingStatement($items);
		$this->class->append_trait_using($decl);
		return $decl;
	}

	public function set_scope_parameters(array $parameters)
	{
		foreach ($parameters as $parameter) {
			$symbol = new Symbol($parameter);
			$this->add_scope_symbol($symbol);
		}

		$this->scope->parameters = $parameters;
	}

	public function create_masked_declaration(string $name)
	{
		$decl = new MaskedDeclaration(_PUBLIC, $name);
		$this->begin_class_member($decl);
		return $decl;
	}

	public function create_method_declaration(?string $modifier, string $name)
	{
		$decl = new MethodDeclaration($modifier, $name);

		switch (strtolower($name)) {
			case '__get':
				$this->class->set_feature(ClassFeature::MAGIC_GET);
				break;
			case '__set':
				$this->class->set_feature(ClassFeature::MAGIC_SET);
				break;
			case '__isset':
				$this->class->set_feature(ClassFeature::MAGIC_ISSET);
				break;
			case '__unset':
				$this->class->set_feature(ClassFeature::MAGIC_UNSET);
				break;
			case '__call':
				$this->class->set_feature(ClassFeature::MAGIC_CALL);
				break;
			case '__callstatic':
				$this->class->set_feature(ClassFeature::MAGIC_CALL_STATIC);
				break;
		}

		$this->begin_class_member($decl);
		return $decl;
	}

	public function create_function_declaration(?string $modifier, string $name, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'function');

		$decl = new FunctionDeclaration($modifier, $name);
		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);

		$this->begin_root_declaration($decl);

		return $decl;
	}

	public function create_property_declaration(?string $modifier, string $name)
	{
		$decl = new PropertyDeclaration($modifier, $name);
		$this->begin_class_member($decl);
		return $decl;
	}

	public function create_class_constant_declaration(?string $modifier, string $name)
	{
		$decl = new ClassConstantDeclaration($modifier, $name);
		$this->begin_class_member($decl);
		return $decl;
	}

	public function create_constant_declaration(?string $modifier, string $name, ?NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'constant');

		$decl = new ConstantDeclaration($modifier, $name);
		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);

		$this->begin_root_declaration($decl);

		return $decl;
	}

	public function create_super_variable_declaration(string $name, IType $type)
	{
		$decl = new SuperVariableDeclaration($name, $type);

		$this->begin_root_declaration($decl);
		$this->create_internal_symbol($decl);

		return $decl;
	}

	public function create_variable_declaration(string $name, ?IType $type = null, ?BaseExpression $value = null)
	{
		$decl = new VariableDeclaration($name, $type, $value);
		$this->create_local_symbol($decl);

		return $decl;
	}

	public function create_anonymous_function()
	{
		$block = new AnonymousFunction();

		$this->scope = $block;
		$this->begin_block($block);

		return $block;
	}

	// public function create_coroutine_block(array $parameters = [])
	// {
	// 	$this->program->is_using_coroutine = true;

	// 	$block = new CoroutineBlock(null, $parameters);

	// 	$this->scope = $block;
	// 	$this->begin_block($block);
	// 	$this->set_scope_parameters($parameters);

	// 	return $block;
	// }

	public function create_if_block(BaseExpression $test)
	{
		$block = new IfBlock($test);
		$this->begin_block($block);
		return $block;
	}

	public function create_elseif_block(BaseExpression $test)
	{
		$block = new ElseIfBlock($test);
		$this->begin_block($block);
		return $block;
	}

	public function create_else_block()
	{
		$block = new ElseBlock();
		$this->begin_block($block);
		return $block;
	}

	public function create_try_block()
	{
		$block = new TryBlock();
		$this->begin_block($block);
		return $block;
	}

	public function create_catch_block(string $var_name, ?ClassKindredIdentifier $type = null)
	{
		$var = new VariableDeclaration($var_name, $type);
		$block = new CatchBlock($var);
		$block->symbols[$var_name] = new Symbol($var);
		$this->begin_block($block);

		return $block;
	}

	public function create_finally_block()
	{
		$block = new FinallyBlock();
		$this->begin_block($block);
		return $block;
	}

	public function create_switch_block(BaseExpression $test_argument)
	{
		$block = new SwitchBlock($test_argument);
		$this->begin_block($block);
		return $block;
	}

	public function create_case_branch_block(array $rule_arguments)
	{
		$block = new CaseBranch($rule_arguments);
		$this->begin_block($block);
		return $block;
	}

	public function count_target_block_layers_with_label(string $label, string $block_interface)
	{
		$layers = 1;
		$switch_layers = 0;
		$block = $this->block;

		do {
			if ($block instanceof IScopeBlock) {
				throw $this->parser->new_parse_error("Block of label '$label' not found in current function");
			}

			if ($block->label === $label) {
				if (!is_subclass_of($block, $block_interface)) {
					throw $this->parser->new_parse_error("Block label '$label' cannot use as target at here");
				}

				break;
			}

			if (is_subclass_of($block, $block_interface)) {
				$layers++;
			}
			elseif ($block instanceof SwitchBlock) {
				$switch_layers++;
			}

			$block = $block->belong_block;
			if ($block === null) {
				throw $this->parser->new_parse_error("An error occurred in the compiler");
			}
		}
		while ($block);

		return [$layers, $switch_layers];
	}

	public function count_switch_layers_contains_in_block(string $block_interface)
	{
		$switch_layers = 0;
		$block = $this->block;

		do {
			if ($block instanceof IScopeBlock) {
				throw $this->parser->new_parse_error("Target block not found");
			}

			if (is_subclass_of($block, $block_interface)) {
				break;
			}
			elseif ($block instanceof SwitchBlock) {
				$switch_layers++;
			}

			$block = $block->belong_block;
			if ($block === null) {
				throw $this->parser->new_parse_error("An error occurred in the compiler");
			}
		}
		while ($block);

		return $switch_layers;
	}

	public function create_for_block(array $args1, array $args2, array $args3)
	{
		$block = new ForBlock($args1, $args2, $args3);
		$this->begin_block($block);

		return $block;
	}

	public function create_foreach_block(BaseExpression $iterable, ?BaseExpression $key, BaseExpression $val)
	{
		$block = new ForEachBlock($iterable, $key, $val);
		$this->begin_block($block);

		if ($key instanceof PlainIdentifier) {
			$this->create_variable_declaration_for_identifier($key);
		}

		if ($val instanceof PrefixOperation) {
			$val = $val->expression;
		}

		if ($val instanceof PlainIdentifier) {
			$this->create_variable_declaration_for_identifier($val);
		}

		return $block;
	}

	public function create_variable_declaration_for_identifier(PlainIdentifier $identifier)
	{
		$name = $identifier->name;
		$decl = new VariableDeclaration($name);

		$identifier->symbol = new Symbol($decl);
		$this->block->symbols[$name] = $identifier->symbol;

		$this->remove_defer_check($identifier);
	}

	public function create_forin_block(?ParameterDeclaration $key, ParameterDeclaration $val, BaseExpression $iterable)
	{
		$block = new ForInBlock($key, $val, $iterable);
		$this->begin_block($block);

		$this->prepare_forin_vars($key, $val, $block);

		return $block;
	}

	public function create_forto_block(?ParameterDeclaration $key, ParameterDeclaration $val, BaseExpression $start, BaseExpression $end, ?int $step)
	{
		$block = new ForToBlock($key, $val, $start, $end, $step);
		$this->begin_block($block);

		$this->prepare_forin_vars($key, $val, $block);

		return $block;
	}

	private function prepare_forin_vars(?ParameterDeclaration $key, ParameterDeclaration $val, ControlBlock $block)
	{
		if ($key) {
			// $key = new VariableDeclaration($key->name);
			$symbol = new Symbol($key);
			$block->symbols[$key->name] = $symbol;
			// $key->symbol = $symbol;
		}

		// $val = new VariableDeclaration($val->name);
		$symbol = new Symbol($val);
		$block->symbols[$val->name] = $symbol;
		// $val->symbol = $symbol;
	}

	public function create_while_block($condition)
	{
		if ($condition instanceof Parentheses) {
			$condition = $condition->expression;
		}

		$block = new WhileBlock($condition);
		$this->begin_block($block);
		return $block;
	}

	public function create_do_while_block()
	{
		$block = new DoWhileBlock();
		$this->begin_block($block);
		return $block;
	}

	public function create_loop_block()
	{
		$block = new LoopBlock();
		$this->begin_block($block);
		return $block;
	}

// --------

	public function end_branches(ControlBlock $node)
	{
		$symbols = $this->dig_intersected_symbols_for_block($node);
		$this->add_symbols_to_block($node->belong_block, $symbols);
	}

	private function dig_intersected_symbols_for_block(ControlBlock $node)
	{
		$symbols = [];
		if ($node instanceof IfBlock) {
			if ($node->else) {
				$symbols = $this->intersect_symbols_with_blocks($node, $node->get_else_branches());
			}
		}
		elseif ($node instanceof TryBlock) {
			// if ($node->catching_all) {
			// 	$symbols = $this->intersect_symbols_with_blocks($node, [$node->catching_all]);
			// }

			// if some exceptions do not be catched, it would be throws
			$symbols = $this->intersect_symbols_with_blocks($node, $node->catchings);

			if ($node->finally) {
				$symbols += $node->finally->symbols;
			}
		}

		return $symbols;
	}

	private function intersect_symbols_with_blocks(ControlBlock $block, array $branches)
	{
		$symbols = $block->symbols;
		foreach ($branches as $branch) {
			if (!$branch->is_transfered) {
				$symbols = array_intersect_key($symbols, $branch->symbols);
			}
		}

		return $symbols;
	}

	private function add_symbols_to_block(IBlock $block, array $symbols)
	{
		foreach ($symbols as $key => $symbol) {
			if (!isset($block->symbols[$key])) {
				$block->symbols[$key] = $symbol;
			}
		}
	}

	public function end_program()
	{
		// reset
		$this->program = null;
		$this->declaration = null;
		$this->block = null;
		$this->scope = null;
	}

	public function begin_class(ClassKindredDeclaration $decl)
	{
		$this->class = $decl;
		$this->declaration = $decl;
		$this->block = null;
		$this->scope = null;

		$this->program->append_declaration($decl);
	}

	public function end_class()
	{
		$this->class = null;
		$this->switch_to_initializer();
	}

	public function begin_class_member(IClassMemberDeclaration $member)
	{
		$symbol = new Symbol($member);
		if (!$this->class->append_member_symbol($symbol)) {
			throw $this->parser->new_parse_error("Duplicated class member '{$member->name}'");
		}

		// if ($member instanceof MethodDeclaration) {
			$this->scope = $member;
		// }

		$this->block = $member;
		$this->declaration = $member;
	}

	public function end_class_member()
	{
		$this->class->append_unknow_identifiers_from_declaration($this->declaration);

		$this->declaration = $this->class;
		$this->scope = null;
	}

	public function begin_root_declaration(IRootDeclaration $decl)
	{
		$this->declaration = $decl;

		if ($decl instanceof FunctionDeclaration) {
			$this->block = $decl;
			$this->scope = $decl;
		}

		$this->program->append_declaration($decl);
	}

	public function end_root_declaration()
	{
		$this->switch_to_initializer();
	}

	private function switch_to_initializer()
	{
		$this->declaration = $this->scope = $this->block = $this->program->initializer;
	}

	public function begin_block(IBlock $block)
	{
		$block->belong_block = $this->block;
		$this->block = $block;
	}

	public function end_block()
	{
		$block = $this->block;

		if ($block->belong_block) {
			$this->block = $block->belong_block;
			if ($block instanceof AnonymousFunction) {
				$this->scope = $this->find_super_scope($block);
			}
		}

		return $block;
	}

	private static function find_super_scope(IBlock $block)
	{
		$block= $block->belong_block;
		if (!$block || $block instanceof IScopeBlock) {
			return $block;
		}

		if (!$block instanceof IBlock) {
			return null;
		}

		return self::find_super_scope($block);
	}

	const GLOBAL_MODIFIERS = [_PUBLIC, _INTERNAL];
	private function check_global_modifier(?string $modifier, string $type_label)
	{
		if ($modifier && !in_array($modifier, self::GLOBAL_MODIFIERS, true)) {
			throw $this->parser->new_parse_error("Cannot use modifier '{$modifier}' for $type_label");
		}
	}

	// // use for include expression
	// private function collect_created_symbols_in_current_function()
	// {
	// 	$block = $this->block;
	// 	$symbols = $block->symbols;

	// 	while (($block = $block->belong_block) && !$block instanceof ClassKindredDeclaration) {
	// 		$symbols = array_merge($symbols, $block->symbols);
	// 	}

	// 	if ($this->class) {
	// 		$symbols[_THIS] = $this->class->this_object_symbol;
	// 	}

	// 	return $symbols;
	// }

	private function attach_local_symbol(Identifiable $identifier, IBlock $seek_block = null)
	{
		if ($seek_block === null) {
			$seek_block = $this->block;
		}

		if (!$seek_block instanceof IBlock) {
			// should be at the begin of a class
			return $this->program->symbols[$identifier->name] ?? null;
		}

		$symbol = $this->seek_symbol_in_encolsing($identifier->name, $seek_block);
		if ($symbol === null && $seek_block) {
			// add to lambda check list
			if ($seek_block instanceof AnonymousFunction) {
				$seek_block->append_unknow_identifier($identifier);
				// $identifier->lambda = $seek_block; // for the mutating feature
			}

			if ($seek_block->belong_block && !$seek_block->belong_block instanceof ClassKindredDeclaration) {
				return $this->attach_local_symbol($identifier, $seek_block->belong_block);
			}
		}

		$attached = false;
		if ($symbol) {
			$identifier->symbol = $symbol;
			$attached = true;
		}

		return $attached;
	}

	private function seek_symbol_in_encolsing(string $name, IBlock &$seek_block): ?Symbol
	{
		do {
			if (isset($seek_block->symbols[$name])) {
				return $seek_block->symbols[$name];
			}

			if ($seek_block instanceof IScopeBlock) {
				break;
			}
		} while ($seek_block = $seek_block->belong_block);

		return null;
	}

	// create symbol, and add to current block
	private function create_local_symbol(IDeclaration $decl)
	{
		$symbol = new Symbol($decl);
		$this->add_block_symbol($symbol);

		return $symbol;
	}

	// // create symbol, and add to scope block, includes: Anonymous Function, Normal Function, Method
	// private function create_scope_symbol(IDeclaration $decl)
	// {
	// 	$symbol = new Symbol($decl);
	// 	$this->add_scope_symbol($symbol);

	// 	return $symbol;
	// }

	private function new_top_symbol(IDeclaration $decl)
	{
		return new TopSymbol($decl);
	}

	private function create_program_symbol(IDeclaration $decl)
	{
		$symbol = $this->new_top_symbol($decl);
		$this->add_program_symbol($symbol);

		return $symbol;
	}

	private function create_internal_symbol(IDeclaration $decl, Symbol $symbol = null)
	{
		$decl->program = $this->program;

		if ($symbol === null) {
			$symbol = $this->new_top_symbol($decl);
		}

		$this->add_program_symbol($symbol);
		$this->add_unit_symbol($symbol);

		return $symbol;
	}

	private function create_external_symbol(IDeclaration $decl, NamespaceIdentifier $ns)
	{
		$decl->set_namespace($ns);
		$decl->program = $this->program;

		$symbol = $this->new_top_symbol($decl);

		$ns_decl = $this->find_or_create_namespace_declaration($decl->ns->names);
		if (isset($ns_decl->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in namespace '{$ns_decl->uri}' of module '{$this->unit->name}'");
		}

		$ns_decl->symbols[$symbol->name] = $symbol;

		return $symbol;
	}

	private function create_symbol_for_top_declaration(RootDeclaration $decl, ?NamespaceIdentifier $ns)
	{
		$special_namespace = $ns !== null && ($this->ns === null || $ns->uri !== $this->ns->uri);

		if ($special_namespace) {
			$symbol = $this->create_external_symbol($decl, $ns);
			if ($this->parser->is_declare_mode) {
				$this->create_internal_symbol($decl, $symbol);
			}
		}
		else {
			$symbol = $this->create_internal_symbol($decl);
		}

		return $symbol;
	}

	private function find_or_create_namespace_declaration(array $namepath)
	{
		$name = array_shift($namepath);

		$decl = $this->unit->namespaces[$name] ?? null;
		if ($decl === null) {
			$decl = $this->create_namespace_declaration($name);
			$this->unit->namespaces[$name] = $decl;
		}

		foreach ($namepath as $sub_name) {
			$sub_decl = $decl->namespaces[$sub_name] ?? null;
			if ($sub_decl === null) {
				$sub_decl = $this->create_namespace_declaration($sub_name);
				$decl->namespaces[$sub_name] = $sub_decl;
			}

			$decl = $sub_decl;
		}

		return $decl;
	}

	private function create_namespace_declaration(string $name)
	{
		return new NamespaceDeclaration($name);
	}

	private function add_unit_symbol(Symbol $symbol)
	{
		if (isset($this->unit->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in module '{$this->unit->name}'");
		}

		$this->unit->symbols[$symbol->name] = $symbol;
	}

	private function add_program_symbol(Symbol $symbol)
	{
		if (isset($this->program->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in current program");
		}

		$this->program->symbols[$symbol->name] = $symbol;
	}

	private function add_scope_symbol(Symbol $symbol)
	{
		$scope = $this->scope;
		if (isset($scope->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in current scope");
		}

		$scope->symbols[$symbol->name] = $symbol;
	}

	private function add_block_symbol(Symbol $symbol)
	{
		$name = $symbol->name;
		if (isset($this->block->symbols[$name])) {
			throw $this->parser->new_parse_error("Symbol '{$name}' is already in use in local block");
		}

		$this->block->symbols[$name] = $symbol;
	}
}

// program end
