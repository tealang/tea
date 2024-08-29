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

	// use for untyped catch
	public $base_exception_identifier;

	public $parser;

	private $ns;

	private $unit;

	private $program;

	private $declaration;

	/**
	 * current classkindred
	 * @var ClasskindredDeclaration
	 */
	private $class;

	// /**
	//  * current function or method
	//  * @var IFunctionDeclaration
	//  */
	// private $function;

	/**
	 * current function or lambda
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
		$this->unit = $unit;
		$this->root_namespace = $this->create_namespace_identifier(['']);

		// the constant 'UNIT_PATH'
		$declaration = new ConstantDeclaration(_PUBLIC, _UNIT_PATH, TypeFactory::$_string, null);
		$this->unit_path_symbol = new Symbol($declaration);


		// use for untyped catch
		$this->base_exception_identifier = new ClassKindredIdentifier(_BASE_EXCEPTION);
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
		$declaration = new UseDeclaration($ns, $target_name, $source_name);
		$this->parser->attach_position($declaration);

		if ($this->parser->is_parsing_header) {
			$this->create_internal_symbol($declaration);
		}
		else {
			$this->create_program_symbol($declaration);
		}

		// need to check
		$this->program->use_targets[] = $declaration;

		return $declaration;
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

	public function create_accessing_identifier(BaseExpression $basing, string $name)
	{
		return new AccessingIdentifier($basing, $name);
	}

	public function create_classkindred_identifier(string $name)
	{
		$identifier = new ClassKindredIdentifier($name);
		$this->set_defer_check($identifier);

		return $identifier;
	}

	public function create_variable_identifier(string $name)
	{
		if (TeaHelper::is_builtin_identifier($name)) {
			$identifier = $this->create_builtin_identifier($name);
		}
		else {
			$identifier = new VariableIdentifier($name);
			$this->set_defer_check($identifier);
		}

		return $identifier;
	}

	public function create_identifier(string $name)
	{
		if (TeaHelper::is_builtin_identifier($name)) {
			$identifier = $this->create_builtin_identifier($name);
		}
		else {
			$identifier = new PlainIdentifier($name);
			$this->set_defer_check($identifier);
		}

		return $identifier;
	}

	public function create_namespace_identifier(array $names)
	{
		$ns = new NamespaceIdentifier($names);
		return $ns;
	}

	public function create_builtin_identifier(string $token): BaseExpression
	{
		switch ($token) {
			case _THIS:
				$identifier = new PlainIdentifier($token);
				if ($this->class) {
					// function/const/property declaration
					$declar = $this->declaration;
					// $declar = $this->function ?? $this->declaration;
					$identifier->symbol = $declar->is_static
						? $this->class->this_class_symbol
						: $this->class->this_object_symbol;
				}
				elseif (!$this->seek_symbol_in_function($identifier)) { // it would be has an #expect
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
				$identifier = $this->create_none_identifier();
				break;
			case _VAL_TRUE:
				$identifier = new LiteralBoolean(true);
				break;
			case _VAL_FALSE:
				$identifier = new LiteralBoolean(false);
				break;
			case _UNIT_PATH:
				$identifier = new ConstantIdentifier(_UNIT_PATH);
				$identifier->symbol = $this->unit_path_symbol;
				break;
			default:
				throw $this->parser->new_parse_error("Unknow builtin identifier '$token'");
		}

		return $identifier;
	}

	public function create_none_identifier()
	{
		return new LiteralNone();
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
		if (!$this->declaration instanceof IFunctionDeclaration or !$this->seek_symbol_in_function($identifier)) {
			$this->declaration->set_defer_check_identifier($identifier);
		}
	}

	public function remove_defer_check(PlainIdentifier $identifier)
	{
		$block = $this->scope;
		$block->remove_defer_check_for_key($identifier->name);

		while ($block = $block->belong_block) {
			if ($block instanceof IScopeBlock) {
				$block->remove_defer_check_for_key($identifier->name);
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
			foreach ($assigned_to->items as $item) {
				if ($item instanceof PlainIdentifier) {
					$this->process_assigned_target($item);
				}
				elseif ($item instanceof DictMember && $item->value instanceof PlainIdentifier) {
					$this->process_assigned_target($item->value);
				}
			}
		}
		elseif (ASTHelper::is_pure_bracket_accessing_expr($assigned_to)) {
			$basing = $assigned_to->get_final_basing();
			$this->process_assigned_target($basing);
		}

		$assignment = new AssignmentOperation($assigned_to, $value, $operator);

		return $assignment;
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

		$declaration = new VariableDeclaration($identifier->name, null, $value);
		$declaration->block = $this->block;

		// link to symbol
		$identifier->symbol = $this->create_local_symbol($declaration);
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

	public static function create_virtual_function(string $name)
	{
		$decl = new FunctionDeclaration(_INTERNAL, $name, null, []);
		$decl->is_dynamic = true;

		$symbol = new Symbol($decl);

		return $decl;
	}

	public static function create_virtual_class(string $name = '__object_class')
	{
		$decl = new ClassDeclaration(null, $name);
		$decl->is_dynamic = true;

		$symbol = new Symbol($decl);
		self::bind_class_symbol($decl, $symbol);

		// do not need other context, it's just for ast-check

		return $decl;
	}

	public static function create_virtual_method(string $name, ClassDeclaration $class)
	{
		$decl = new MethodDeclaration(null, $name);
		$decl->is_dynamic = true;
		$decl->infered_type = TypeFactory::$_any;
		$decl->parameters = [];

		new Symbol($decl);
		$class->append_member($decl);

		return $decl;
	}

	public static function create_virtual_property(string $name, ClassDeclaration $class)
	{
		$decl = new PropertyDeclaration(null, $name, TypeFactory::$_any);
		$decl->is_dynamic = true;
		$decl->infered_type = TypeFactory::$_any;

		new Symbol($decl);
		$class->append_member($decl);

		return $decl;
	}

	public function create_object_member(?string $quote_mark, string $name)
	{
		$declaration = new ObjectMember($name, $quote_mark);
		new Symbol($declaration);

		// $this->begin_class_member($declaration);

		return $declaration;
	}

	public function create_builtin_type_class_declaration(string $name)
	{
		$type_identifier = TypeFactory::get_type($name);
		if ($type_identifier === null) {
			return null;
		}

		$declaration = new BuiltinTypeClassDeclaration(_PUBLIC, $name);

		$symbol = $this->create_internal_symbol($declaration);
		$this->bind_class_symbol($declaration, $symbol);

		// bind to type
		$type_identifier->symbol = $symbol;

		$this->begin_class($declaration);

		return $declaration;
	}

	public function create_class_declaration(string $name, string $modifier, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'class');

		$declaration = new ClassDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($declaration, $ns);
		$this->bind_class_symbol($declaration, $symbol);

		$this->begin_class($declaration);

		return $declaration;
	}

	public function create_interface_declaration(string $name, string $modifier, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'interface');

		$declaration = new InterfaceDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($declaration, $ns);
		$this->bind_class_symbol($declaration, $symbol);

		$this->begin_class($declaration);

		return $declaration;
	}

	public function create_intertrait_declaration(string $name, string $modifier, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'intertrait');

		$declaration = new IntertraitDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($declaration, $ns);
		$this->bind_class_symbol($declaration, $symbol);

		$this->begin_class($declaration);

		return $declaration;
	}

	public function create_trait_declaration(string $name, string $modifier, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'trait');

		$declaration = new TraitDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($declaration, $ns);
		$this->bind_class_symbol($declaration, $symbol);

		$this->begin_class($declaration);

		return $declaration;
	}

	private static function bind_class_symbol(ClassKindredDeclaration $declaration, Symbol $symbol)
	{
		// create 'this' symbol
		$class_identifier = new ClassKindredIdentifier($declaration->name); // as a Type for 'this'
		$class_identifier->symbol = $symbol;
		// $declaration->symbols[_THIS] = self::create_symbol_this($class_identifier);

		$declaration->this_class_symbol = $symbol;
		$declaration->this_object_symbol = self::create_symbol_this($class_identifier);

		// create the MetaType
		$declaration->declared_type = TypeFactory::create_meta_type($class_identifier);
	}

	private static function create_symbol_this(ClassKindredIdentifier $class)
	{
		$declaration = new FinalVariableDeclaration(_THIS, $class);
		$declaration->is_checked = true; // do not need to check
		return new Symbol($declaration);
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
		$declaration = new MaskedDeclaration(_PUBLIC, $name);
		$this->new_top_symbol($declaration);

		$this->begin_class_member($declaration);

		$this->declaration = $declaration;
		$this->scope = $declaration;
		// $this->function = $declaration;
		$this->block = $declaration;

		return $declaration;
	}

	public function create_traits_using_statement(array $items)
	{
		$declaration = new TraitsUsingStatement($items);
		$this->class->append_trait_using($declaration);

		return $declaration;
	}

	public function create_method_declaration(?string $modifier, string $name)
	{
		$declaration = new MethodDeclaration($modifier, $name);
		new Symbol($declaration);

		$this->begin_class_member($declaration);

		return $declaration;
	}

	public function create_function_declaration(?string $modifier, string $name, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'function');

		$declaration = new FunctionDeclaration($modifier, $name);
		$symbol = $this->create_symbol_for_top_declaration($declaration, $ns);

		$this->begin_root_declaration($declaration);

		return $declaration;
	}

	public function create_property_declaration(?string $modifier, string $name)
	{
		$declaration = new PropertyDeclaration($modifier, $name);
		new Symbol($declaration);

		$this->begin_class_member($declaration);

		return $declaration;
	}

	public function create_class_constant_declaration(?string $modifier, string $name)
	{
		$declaration = new ClassConstantDeclaration($modifier, $name);
		new Symbol($declaration);

		$this->begin_class_member($declaration);

		return $declaration;
	}

	public function create_constant_declaration(?string $modifier, string $name, ?NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'constant');

		$declaration = new ConstantDeclaration($modifier, $name);
		$symbol = $this->create_symbol_for_top_declaration($declaration, $ns);

		$this->begin_root_declaration($declaration);

		return $declaration;
	}

	public function create_super_variable_declaration(string $name, IType $type)
	{
		$declaration = new SuperVariableDeclaration($name, $type);

		$this->begin_root_declaration($declaration);
		$this->create_internal_symbol($declaration);

		return $declaration;
	}

	public function create_variable_declaration(string $name, ?IType $type = null, ?BaseExpression $value = null)
	{
		$declaration = new VariableDeclaration($name, $type, $value);
		$this->create_local_symbol($declaration);

		return $declaration;
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

	public function create_catch_block(string $var_name, ?ClassKindredIdentifier $type)
	{
		$var_declaration = new VariableDeclaration($var_name, $type ?? $this->base_exception_identifier);

		$block = new CatchBlock($var_declaration);
		$block->symbols[$var_name] = new Symbol($var_declaration);

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
			// use String as the default type, because String can be compatible with Int/UInt
			$key_declar = new VariableDeclaration($key->name);
			$block->symbols[$key->name] = $key->symbol = new Symbol($key_declar);
		}

		$val_declar = new VariableDeclaration($val->name);
		$block->symbols[$val->name] = $val->symbol = new Symbol($val_declar);
	}

	public function create_while_block($condition)
	{
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
			if ($node->catching_all) {
				$symbols = $this->intersect_symbols_with_blocks($node, [$node->catching_all]);
			}

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
			if (!$branch->is_ended_function) {
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
		// $this->function = null;
		$this->scope = null;
	}

	public function begin_class(ClassKindredDeclaration $declaration)
	{
		$this->class = $declaration;
		$this->declaration = $declaration;
		$this->block = null;
		// $this->function = null;
		$this->scope = null;
	}

	public function end_class()
	{
		$this->class = null;
		$this->switch_to_initializer();
	}

	public function begin_class_member(IClassMemberDeclaration $declaration)
	{
		if (!$this->class->append_member($declaration)) {
			throw $this->parser->new_parse_error("Class member '{$declaration->name}' of '{$this->class->name}' has duplicated");
		}

		if ($declaration instanceof MethodDeclaration) {
			$this->scope = $declaration;
			// $this->function = $declaration;
		}

		$this->block = $declaration;
		$this->declaration = $declaration;
	}

	public function end_class_member()
	{
		$this->class->append_defer_check_identifiers($this->declaration);

		$this->declaration = $this->class;
		$this->scope = null;
		// $this->function = null;
	}

	public function begin_root_declaration(IRootDeclaration $declaration)
	{
		$this->declaration = $declaration;

		if ($declaration instanceof FunctionDeclaration) {
			$this->block = $declaration;
			$this->scope = $declaration;
			// $this->function = $declaration;
		}
	}

	public function end_root_declaration()
	{
		$this->switch_to_initializer();
	}

	private function switch_to_initializer()
	{
		$this->declaration = $this->scope = $this->block = $this->program->initializer;
		// $this->function = $this->declaration;
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

	// use for include expression
	private function collect_created_symbols_in_current_function()
	{
		$block = $this->block;
		$symbols = $block->symbols;

		while (($block = $block->belong_block) && !$block instanceof ClassKindredDeclaration) {
			$symbols = array_merge($symbols, $block->symbols);
		}

		if ($this->class) {
			$symbols[_THIS] = $this->class->this_object_symbol;
		}

		return $symbols;
	}

	private function seek_symbol_in_function(Identifiable $identifier, IBlock $seek_block = null)
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
				$seek_block->set_defer_check_identifier($identifier);
				// $identifier->lambda = $seek_block; // for the mutating feature
			}

			if ($seek_block->belong_block && !$seek_block->belong_block instanceof ClassKindredDeclaration) {
				return $this->seek_symbol_in_function($identifier, $seek_block->belong_block);
			}
		}

		if ($symbol) {
			$identifier->symbol = $symbol;
		}

		return $symbol;
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
	private function create_local_symbol(IDeclaration $declaration)
	{
		$symbol = new Symbol($declaration);
		$this->add_block_symbol($symbol);

		return $symbol;
	}

	// // create symbol, and add to scope block, includes: Anonymous Function, Normal Function, Method
	// private function create_scope_symbol(IDeclaration $declaration)
	// {
	// 	$symbol = new Symbol($declaration);
	// 	$this->add_scope_symbol($symbol);

	// 	return $symbol;
	// }

	private function new_top_symbol(IDeclaration $declaration)
	{
		return new TopSymbol($declaration);
	}

	private function create_program_symbol(IDeclaration $declaration)
	{
		$symbol = $this->new_top_symbol($declaration);
		$this->add_program_symbol($symbol);

		return $symbol;
	}

	private function create_internal_symbol(IDeclaration $declaration, Symbol $symbol = null)
	{
		$declaration->program = $this->program;

		if ($symbol === null) {
			$symbol = $this->new_top_symbol($declaration);
		}

		$this->add_program_symbol($symbol);
		$this->add_unit_symbol($symbol);

		return $symbol;
	}

	private function create_external_symbol(IDeclaration $declaration, NamespaceIdentifier $ns)
	{
		$declaration->set_namespace($ns);
		$declaration->program = $this->program;

		$symbol = $this->new_top_symbol($declaration);

		$ns_decl = $this->find_or_create_namespace_declaration($declaration->ns->names);
		if (isset($ns_decl->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in namespace '{$ns_decl->uri}' of module '{$this->unit->name}'");
		}

		$ns_decl->symbols[$symbol->name] = $symbol;

		return $symbol;
	}

	private function create_symbol_for_top_declaration(RootDeclaration $declaration, ?NamespaceIdentifier $ns)
	{
		$special_namespace = $ns !== null && ($this->ns === null || $ns->uri !== $this->ns->uri);

		if ($special_namespace) {
			$symbol = $this->create_external_symbol($declaration, $ns);
			if ($this->parser->is_declare_mode) {
				$this->create_internal_symbol($declaration, $symbol);
			}
		}
		else {
			$symbol = $this->create_internal_symbol($declaration);
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
		if (isset($this->scope->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in current scope");
		}

		$this->scope->symbols[$symbol->name] = $symbol;
	}

	private function add_block_symbol(Symbol $symbol)
	{
		if (isset($this->block->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in local block");
		}

		$this->block->symbols[$symbol->name] = $symbol;
	}
}

// program end
