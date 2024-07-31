<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ASTFactory
{
	// for #default label
	public static $default_value_marker;

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

	private $class;

	private $function;

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

		// just use None to simplify the processes
		self::$default_value_marker = new LiteralNone();
		self::$default_value_marker->is_default_value_marker = true;
	}

	public function __construct(Unit $unit)
	{
		$this->unit = $unit;
		$this->root_namespace = $this->create_namespace_identifier(['']);

		// the constant 'UNIT_PATH'
		$declaration = new ConstantDeclaration(_PUBLIC, _UNIT_PATH, TypeFactory::$_string, null);
		$this->unit_path_symbol = new Symbol($declaration);


		// use for untyped catch
		$this->base_exception_identifier = new ClassKindredIdentifier('Exception');
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

	public function create_accessing_identifier(BaseExpression $master, string $name)
	{
		return new AccessingIdentifier($master, $name);
	}

	public function create_classkindred_identifier(string $name)
	{
		$identifier = new ClassKindredIdentifier($name);
		$this->set_defer_check($identifier);

		return $identifier;
	}

	// public static function create_variable_identifier(VariableDeclaration $declaration)
	// {
	// 	$symbol = new Symbol($declaration);
	// 	$identifier = VariableIdentifier::create_with_symbol($symbol);

	// 	return $identifier;
	// }

	public function create_identifier(string $name) // PlainIdentifier or ILiteral
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
		if ($token === _THIS) {
			$identifier = new PlainIdentifier($token);
			if ($this->class) {
				// if ($this->function !== $this->scope) { // it is would be in a lambda block
				// 	throw $this->parser->new_parse_error("Cannot use '$token' identifier in lambda functions");
				// }
				// else
				if ($this->function) {
					$identifier->symbol = $this->function->is_static ? $this->class->this_class_symbol : $this->class->this_object_symbol;
				}
				else {
					// the const/property declaration
					$identifier->symbol = $this->declaration->is_static ? $this->class->this_class_symbol : $this->class->this_object_symbol;
					// throw $this->parser->new_parse_error("Can not use 'this' on class member declaration");
				}
			}
			elseif (!$this->seek_symbol_in_function($identifier)) { // it would be has an #expect
				throw $this->parser->new_parse_error("Identifier '$token' not defined");
			}
		}
		elseif ($token === _SUPER) {
			$identifier = new PlainIdentifier($token);
			if ($this->class) {
				if ($this->function !== $this->scope) { // it is would be in a lambda block
					throw $this->parser->new_parse_error("Cannot use '$token' identifier in lambda functions");
				}
			}
			else {
				throw $this->parser->new_parse_error("Identifier '$token' cannot use without in a class");
			}
		}
		elseif ($token === _VAL_TRUE) {
			$identifier = new LiteralBoolean(true);
		}
		elseif ($token === _VAL_FALSE) {
			$identifier = new LiteralBoolean(false);
		}
		elseif ($token === TeaParser::VAL_NONE) {
			$identifier = $this->create_none_identifier();
		}
		elseif ($token === _UNIT_PATH) {
			$identifier = new ConstantIdentifier(_UNIT_PATH);
			$identifier->symbol = $this->unit_path_symbol;
		}
		else {
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
		// force set type YieldGenerator to current function
		$this->function->hinted_type = TypeFactory::$_yield_generator;

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
		$block->remove_defer_check_identifier($identifier);

		while ($block = $block->belong_block) {
			if ($block instanceof IScopeBlock) {
				$block->remove_defer_check_identifier($identifier);
			}
		}
	}

	public function create_assignment(IAssignable $assignable, BaseExpression $value, string $operator = null)
	{
		if ($assignable instanceof PlainIdentifier) {
			if ($assignable->symbol) {
				// no any
			}
			else {
				// symbol has not declared
				$this->auto_declare_for_assigning_identifier($assignable);

				// remove from check list
				$this->remove_defer_check($assignable);
			}
		}
		elseif ($assignable instanceof IAssignable) {
			// includes AccessingIdentifier / KeyAccessing / SquareAccessing
			// post-check required
		}
		else {
			throw $this->parser->new_parse_error('Invalid assignment.');
		}

		$assignment = _ASSIGN === $operator
			? new Assignment($assignable, $value)
			: new CompoundAssignment($operator, $assignable, $value);

		return $assignment;
	}

	private function auto_declare_for_assigning_identifier(BaseExpression $identifier)
	{
		if (!TeaHelper::is_normal_variable_name($identifier->name)) {
			throw $this->parser->new_parse_error("Identifier '$identifier->name' not a valid variable name");
		}

		$declaration = new VariableDeclaration($identifier->name);
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

		$this->set_initializer();

		return $program;
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

	public function create_virtual_class_declaration()
	{
		$declaration = new ClassDeclaration(null, '__object_class');

		$symbol = new Symbol($declaration);
		$this->bind_class_symbol($declaration, $symbol);

		// do not create class context, because it not a normal class, it just for ast-check

		// $this->pushed_env = [$this->class, $this->declaration, $this->function, $this->scope, $this->block];
		// $this->begin_class($declaration);

		return $declaration;
	}

	public function create_property_declaration_for_virtual_class(?string $quote_mark, string $name)
	{
		$declaration = new ObjectMember($name, $quote_mark);
		new Symbol($declaration);

		// $this->begin_class_member($declaration);

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

	private function bind_class_symbol(ClassKindredDeclaration $declaration, Symbol $symbol)
	{
		// create 'this' symbol
		$class_identifier = new ClassKindredIdentifier($declaration->name); // as a Type for 'this'
		$class_identifier->symbol = $symbol;
		// $declaration->symbols[_THIS] = self::create_symbol_this($class_identifier);

		$declaration->this_class_symbol = $symbol;
		$declaration->this_object_symbol = self::create_symbol_this($class_identifier);

		// create the MetaType
		$declaration->hinted_type = TypeFactory::create_meta_type($class_identifier);
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
		$this->function = $declaration;
		$this->block = $declaration;

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

	public function create_constant_declaration(?string $modifier, string $name, NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'constant');

		$declaration = new ConstantDeclaration($modifier, $name);
		$symbol = $this->create_symbol_for_top_declaration($declaration, $ns);

		$this->begin_root_declaration($declaration);

		return $declaration;
	}

	// public function create_super_variable_declaration(string $name, IType $type = null)
	// {
	// 	$declaration = new SuperVariableDeclaration($name, $type);

	// 	$this->begin_root_declaration($declaration);
	// 	$this->create_internal_symbol($declaration);

	// 	return $declaration;
	// }

	public function create_variable_declaration(string $name, IType $type = null, BaseExpression $value = null)
	{
		$declaration = new VariableDeclaration($name, $type, $value);
		$this->create_local_symbol($declaration);

		return $declaration;
	}

	public function create_lambda_expression(IType $type = null, array $parameters)
	{
		$block = new LambdaExpression($type, $parameters);

		$this->scope = $block;
		$this->begin_block($block);
		$this->set_scope_parameters($parameters);

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

	public function create_switch_block(BaseExpression $argument)
	{
		$block = new SwitchBlock($argument);
		$this->begin_block($block);
		return $block;
	}

	public function create_case_branch_block(BaseExpression $rule)
	{
		$block = new CaseBranch($rule);
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

	public function create_forin_block(?VariableIdentifier $key, VariableIdentifier $val, BaseExpression $iterable)
	{
		$block = new ForInBlock($key, $val, $iterable);
		$this->begin_block($block);

		$this->prepare_forblock_vars($key, $val, $block);

		return $block;
	}

	public function create_forto_block(?VariableIdentifier $key, VariableIdentifier $val, BaseExpression $start, BaseExpression $end, ?int $step)
	{
		$block = new ForToBlock($key, $val, $start, $end, $step);
		$this->begin_block($block);

		$this->prepare_forblock_vars($key, $val, $block);

		return $block;
	}

	private function prepare_forblock_vars(?VariableIdentifier $key, VariableIdentifier $val, ControlBlock $block)
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

	public function create_loop_block()
	{
		$block = new LoopBlock();
		$this->begin_block($block);
		return $block;
	}

// --------

	public function end_program()
	{
		$program = $this->program;
		// $program->append_defer_check_identifiers($this->function);

		// reset
		$this->program = null;
		$this->declaration = null;
		$this->block = $this->function = $this->scope = null;
	}

	public function begin_class(ClassKindredDeclaration $declaration)
	{
		$this->class = $declaration;
		$this->declaration = $declaration;
		$this->block = $this->function = $this->scope = null;
	}

	public function end_class()
	{
		$this->class = null;
		$this->set_initializer();
	}

	public function begin_class_member(IClassMemberDeclaration $declaration)
	{
		if (!$this->class->append_member($declaration)) {
			throw $this->parser->new_parse_error("Class member '{$declaration->name}' of '{$this->class->name}' has duplicated");
		}

		if ($declaration instanceof MethodDeclaration) {
			$this->scope = $declaration;
			$this->function = $declaration;
		}

		$this->block = $declaration;
		$this->declaration = $declaration;
	}

	public function end_class_member()
	{
		$this->class->append_defer_check_identifiers($this->declaration);

		$this->declaration = $this->class;
		$this->scope = null;
		$this->function = null;
	}

	public function begin_root_declaration(IRootDeclaration $declaration)
	{
		$this->declaration = $declaration;

		if ($declaration instanceof FunctionDeclaration) {
			$this->block = $declaration;
			$this->scope = $declaration;
			$this->function = $declaration;
		}
	}

	public function end_root_declaration()
	{
		$this->set_initializer();
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
			if ($block instanceof LambdaExpression) {
				$this->scope = $this->find_super_scope($block);
			}
		}
		else {
			$this->set_initializer();
		}

		return $block;
	}

	private function set_initializer()
	{
		$this->declaration = $this->function = $this->scope = $this->block = $this->program->initializer;
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
			if ($seek_block instanceof LambdaExpression) {
				$seek_block->set_defer_check_identifier($identifier);
				$identifier->lambda = $seek_block; // for the mutating feature
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
		$ns_name = array_shift($namepath);

		$ns_decl = $this->unit->namespaces[$ns_name] ?? null;
		if ($ns_decl === null) {
			$ns_decl = $this->create_namespace_declaration($ns_name);
			$this->unit->namespaces[$ns_name] = $ns_decl;
		}

		foreach ($namepath as $sub_ns_name) {
			$sub_ns_decl = $ns_decl->namespaces[$sub_ns_name] ?? null;
			if ($sub_ns_decl === null) {
				$sub_ns_decl = $this->create_namespace_declaration($sub_ns_name);
				$ns_decl->namespaces[$sub_ns_name] = $sub_ns_decl;
			}

			$ns_decl = $sub_ns_decl;
		}

		return $ns_decl;
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
