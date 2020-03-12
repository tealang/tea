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
	public static $virtual_property_for_any;

	// use for untyped catch
	public $base_exception_identifier;

	public $parser;

	private $unit;

	private $program;

	private $declaration;

	private $class;

	private $function;

	/**
	 * current function or lambda
	 * @var IEnclosingBlock
	 */
	private $enclosing;

	/**
	 * @var BaseBlock
	 */
	private $block;

	public static function init_ast_system()
	{
		TypeFactory::init();
		OperatorFactory::init();

		// just use None to simplify the processes
		self::$default_value_marker = new NoneLiteral();
		self::$default_value_marker->is_default_value_marker = true;
		self::$virtual_property_for_any = new PropertyDeclaration(null, '__vprop__', TypeFactory::$_any, null);
	}

	public function __construct(Unit $unit)
	{
		$this->unit = $unit;

		// the constant 'UNIT_PATH'
		$declaration = new ConstantDeclaration(_PUBLIC, _UNIT_PATH, TypeFactory::$_string, null);
		$this->unit_path_symbol = new Symbol($declaration);


		// use for untyped catch
		$this->base_exception_identifier = new ClassLikeIdentifier('Exception');
	}

	public function set_as_main_program()
	{
		$this->program->as_main_program = true;
		$this->unit->as_main_unit = true;
	}

	public function set_namespace(NamespaceIdentifier $ns)
	{
		if ($this->unit->ns) {
			throw $this->parser->new_parse_error("Cannot redeclare the unit namespace.");
		}

		$this->unit->ns = $ns;
	}

	public function set_unit_option(string $key, string $value)
	{
		$this->unit->{$key} = $value;
	}

	public function create_use_statement(NamespaceIdentifier $ns, array $targets = [])
	{
		// add to unit uses
		$this->unit->use_units[$ns->uri] = $ns;

		return $this->program->uses[] = new UseStatement($ns, $targets);
	}

	public function append_use_target(NamespaceIdentifier $ns, string $target_name, string $source_name = null)
	{
		$declaration = new UseDeclaration($ns, $target_name, $source_name);
		$symbol = new Symbol($declaration);

		if ($this->program->name === '__unit') {
			$this->create_global_symbol($declaration, $symbol);
		}
		else {
			$this->create_program_symbol($declaration, $symbol);
		}

		// need to check
		$this->program->use_targets[] = $declaration;

		return $declaration;
	}

	public function create_namespace_identifier(array $names)
	{
		return new NamespaceIdentifier($names);
	}

	public function create_accessing_identifier(IExpression $master, string $name)
	{
		return new AccessingIdentifier($master, $name);
	}

	public function create_classlike_identifier(string $name)
	{
		$identifier = new ClassLikeIdentifier($name);
		$this->set_defer_check($identifier);
		return $identifier;
	}

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

	private function create_builtin_identifier(string $token): IExpression
	{
		if ($token === _THIS) {
			$identifier = new PlainIdentifier($token);
			if ($this->class) {
				if ($this->function !== $this->enclosing) { // it is would be in a lambda block
					throw $this->parser->new_parse_error("Cannot use '$token' identifier in lambda functions.");
				}

				$identifier->symbol = $this->function->is_static ? $this->class->this_class_symbol : $this->class->this_object_symbol;
			}
			elseif (!$this->seek_symbol_in_function($identifier)) { // it would be has an #expect
				throw $this->parser->new_parse_error("Identifier '$token' not defined.");
			}
		}
		elseif ($token === _SUPER) {
			$identifier = new PlainIdentifier($token);
			if ($this->class) {
				if ($this->function !== $this->enclosing) { // it is would be in a lambda block
					throw $this->parser->new_parse_error("Cannot use '$token' identifier in lambda functions.");
				}
			}
			else {
				throw $this->parser->new_parse_error("Identifier '$token' cannot use without in a class.");
			}
		}
		elseif ($token === _VAL_TRUE) {
			$identifier = new BooleanLiteral(true);
		}
		elseif ($token === _VAL_FALSE) {
			$identifier = new BooleanLiteral(false);
		}
		elseif ($token === _VAL_NONE) {
			$identifier = new NoneLiteral();
		}
		elseif ($token === _UNIT_PATH) {
			$identifier = new ConstantIdentifier(_UNIT_PATH);
			$identifier->symbol = $this->unit_path_symbol;
		}
		else {
			throw $this->parser->new_parse_error("Unknow builtin identifier '$token'.");
		}

		return $identifier;
	}

	public function create_include_expression(string $target)
	{
		$expression = new IncludeExpression($target);

		// prepare for check #expect of target program
		$expression->symbols = $this->collect_created_symbols_in_current_function();

		return $expression;
	}

	public function create_yield_expression(IExpression $argument)
	{
		// force set type IGenerator to current function
		$this->function->type = new ClassLikeIdentifier('IGenerator');
		$this->function->has_yield = true;

		return new YieldExpression($argument);
	}

	private function set_defer_check(Identifiable $identifier)
	{
		if (!$this->declaration instanceof FunctionDeclaration) {
			$this->declaration->set_defer_check_identifier($identifier);
		}
		elseif (!$this->seek_symbol_in_function($identifier)) {
			$this->declaration->set_defer_check_identifier($identifier);
			// // add to check list
			// if ($this->function) {
			// 	$this->function->set_defer_check_identifier($identifier);
			// }
			// else {
			// 	$this->program->set_defer_check_identifier($identifier);
			// }
		}
	}

	public function remove_defer_check(PlainIdentifier $identifier)
	{
		$block = $this->enclosing;
		$block->remove_defer_check_identifier($identifier);

		while ($block = $block->super_block) {
			if ($block instanceof IEnclosingBlock) {
				$block->remove_defer_check_identifier($identifier);
			}
		}
	}

	public function create_assignment(IAssignable $assignable, IExpression $value, string $operator = null)
	{
		if ($assignable instanceof PlainIdentifier) {
			if ($assignable->symbol) {
				if (!$assignable->is_assignable()) {
					throw $this->parser->new_parse_error("Cannot assign to non-assignable item '{$assignable->name}'.");
				}
			}
			else {
				// symbol has not declared
				$this->auto_declare_for_assigning_identifier($assignable);

				// remove from check list
				$this->remove_defer_check($assignable);
			}
		}
		elseif ($assignable instanceof AccessingIdentifier || $assignable instanceof KeyAccessing) {
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

	private function auto_declare_for_assigning_identifier(IExpression $identifier)
	{
		if (!TeaHelper::is_declarable_variable_name($identifier->name)) {
			throw $this->parser->new_parse_error("Identifier '$identifier->name' not a valid variable name.");
		}

		$declaration = new VariableDeclaration($identifier->name, null, null, true);
		$declaration->block = $this->block;
		// $this->enclosing->auto_declarations[$identifier->name] = $declaration;

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

		$program->main_function = new FunctionDeclaration(_INTERNAL, '__main', null, []);
		$program->main_function->program = $program;

		$this->program = $program;
		$this->unit->programs[$program->name] = $program;

		$this->set_main_function();

		return $program;
	}

	public function create_program_expection(ParameterDeclaration ...$parameters)
	{
		$main_function = $this->program->main_function;
		if ($main_function->parameters) {
			throw $this->parser->new_parse_error("'#expect' statement has duplicated.");
		}
		elseif (!$parameters) {
			throw $this->parser->new_parse_error("'#expect' statement required parameters.");
		}

		foreach ($parameters as $parameter) {
			$symbol = new Symbol($parameter);
			$main_function->symbols[$parameter->name] = $symbol;
		}

		$main_function->parameters = $parameters;
		$declaration = new ExpectDeclaration(...$parameters);
		$declaration->program = $this->program;

		return $declaration;
	}

	public function create_builtin_type_class_declaration(string $name)
	{
		$type_identifier = TypeFactory::get_type($name);
		if ($type_identifier === null) {
			return null;
		}

		$declaration = new BuiltinTypeClassDeclaration(_PUBLIC, $name);
		$this->begin_class($declaration);
		$symbol = $this->create_class_symbol($declaration);

		// bind to type
		$type_identifier->symbol = $symbol;

		return $declaration;
	}

	/**
	 * @param $name string
	 */
	public function create_class_declaration(string $name, string $modifier)
	{
		$this->check_global_modifier($modifier, 'class');

		$declaration = new ClassDeclaration($modifier, $name);
		$this->begin_class($declaration);
		$this->create_class_symbol($declaration);

		return $declaration;
	}

	public function create_interface_declaration(string $name, string $modifier)
	{
		$this->check_global_modifier($modifier, 'interface');

		$declaration = new InterfaceDeclaration($modifier, $name);
		$this->begin_class($declaration);
		$this->create_class_symbol($declaration);

		return $declaration;
	}

	public function create_class_symbol(ClassLikeDeclaration $declaration)
	{
		// create symbol
		$symbol = $this->create_global_symbol($declaration);

		// create 'this' symbol
		$class_identifier = new ClassLikeIdentifier($declaration->name); // as a Type for this
		$class_identifier->symbol = $symbol;
		// $declaration->symbols[_THIS] = ASTHelper::create_symbol_this($class_identifier);

		$declaration->this_class_symbol = $symbol;
		$declaration->this_object_symbol = ASTHelper::create_symbol_this($class_identifier);

		// create the MetaType
		$declaration->type = TypeFactory::create_meta_type($class_identifier);

		return $symbol;
	}

	public function set_enclosing_parameters(array $parameters)
	{
		foreach ($parameters as $parameter) {
			$symbol = new Symbol($parameter);
			$this->add_enclosing_symbol($symbol);
		}

		$this->enclosing->parameters = $parameters;
	}

	public function create_masked_declaration(string $name)
	{
		$declaration = new MaskedDeclaration(_PUBLIC, $name);
		$this->begin_class_member($declaration);

		$this->declaration = $declaration;
		$this->enclosing = $declaration;
		$this->function = $declaration;
		$this->block = $declaration;

		return $declaration;
	}

	public function create_method_declaration(?string $modifier, string $name)
	{
		$declaration = new FunctionDeclaration($modifier, $name);
		$this->begin_class_member($declaration);

		return $declaration;
	}

	public function create_function_declaration(string $modifier, string $name)
	{
		$this->check_global_modifier($modifier, 'function');

		$declaration = new FunctionDeclaration($modifier, $name);

		$this->begin_root_declaration($declaration);
		$this->create_global_symbol($declaration);

		return $declaration;
	}

	public function create_property_declaration(?string $modifier, string $name)
	{
		$declaration = new PropertyDeclaration($modifier, $name);
		$this->begin_class_member($declaration);

		return $declaration;
	}

	public function create_class_constant_declaration(?string $modifier, string $name)
	{
		$declaration = new ClassConstantDeclaration($modifier, $name);
		$this->begin_class_member($declaration);

		return $declaration;
	}

	public function create_constant_declaration(string $modifier, string $name)
	{
		$this->check_global_modifier($modifier, 'non class member constant');

		$declaration = new ConstantDeclaration($modifier, $name);

		$this->begin_root_declaration($declaration);
		$symbol = $this->create_global_symbol($declaration);
		// $this->add_enclosing_symbol($symbol);

		return $declaration;
	}

	public function create_super_variable_declaration(string $name, IType $type = null)
	{
		$declaration = new SuperVariableDeclaration($name, $type, null, true);

		$this->begin_root_declaration($declaration);
		$this->create_global_symbol($declaration);

		return $declaration;
	}

	public function create_variable_declaration(string $name, IType $type = null, IExpression $value = null)
	{
		$declaration = new VariableDeclaration($name, $type, $value, true);
		$this->create_local_symbol($declaration);

		return $declaration;
	}

	public function create_lambda_expression(IType $type = null, array $parameters)
	{
		$block = new LambdaExpression($type, $parameters);

		$this->enclosing = $block;
		$this->begin_block($block);
		$this->set_enclosing_parameters($parameters);

		return $block;
	}

	public function create_if_block(IExpression $test)
	{
		$block = new IfBlock($test);
		$this->begin_block($block);
		return $block;
	}

	public function create_elseif_block(IExpression $test)
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

	public function create_catch_block(string $var_name, ?ClassLikeIdentifier $type)
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

	public function create_when_block(IExpression $argument)
	{
		$block = new WhenBlock($argument);
		$this->begin_block($block);
		return $block;
	}

	public function create_when_branch_block(IExpression $rule)
	{
		$block = new WhenBranch($rule);
		$this->begin_block($block);
		return $block;
	}

	public function require_labeled_layer_number(string $label, bool $is_continue_statement = false)
	{
		$layer = 0;
		$block = $this->block;

		if (!$block instanceof ILoopLikeBlock || $block->label !== $label) {
			if ($block instanceof ILoopLikeBlock) {
				$layer++;
			}

			while ($block = $block->super_block) {
				if ($block instanceof IEnclosingBlock) {
					break;
				}
				elseif (!$block instanceof ILoopLikeBlock) {
					continue;
				}

				$layer++;
				if ($block->label === $label) {
					break;
				}
			}

			if (!$block) {
				throw $this->parser->new_parse_error("Block of label '$label' not found.");
			}
		}

		if ($is_continue_statement && !$block instanceof IContinueAble) {
			throw $this->parser->new_parse_error("Block of label '$label' cannot use to the continue statement.");
		}

		return $layer;
	}

	public function create_forin_block(IExpression $iterable, ?VariableIdentifier $key_var, VariableIdentifier $value_var)
	{
		$block = new ForInBlock($iterable, $key_var, $value_var);
		$this->begin_block($block);

		if ($key_var) {
			$key_declaration = new VariableDeclaration($key_var->name, TypeFactory::$_string);
			$block->symbols[$key_var->name] = $key_var->symbol = new Symbol($key_declaration);
		}

		$value_declaration = new VariableDeclaration($value_var->name, TypeFactory::$_any);
		$block->symbols[$value_var->name] = $value_var->symbol = new Symbol($value_declaration);

		return $block;
	}

	public function create_forto_block(VariableIdentifier $value_var, IExpression $start, IExpression $end, ?int $step)
	{
		$block = new ForToBlock($value_var, $start, $end, $step);
		$this->begin_block($block);

		$value_declaration = new VariableDeclaration($value_var->name, TypeFactory::$_any);
		$block->symbols[$value_var->name] = $value_var->symbol = new Symbol($value_declaration);

		return $block;
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
		$this->program->append_defer_check_identifiers($this->function);
		$this->program = null;
		$this->declaration = null;
		$this->block = $this->function = $this->enclosing = null;
	}

	public function begin_class(ClassLikeDeclaration $declaration)
	{
		$this->class = $declaration;
		$this->declaration = $declaration;
		$this->block = $this->function = $this->enclosing = null;
	}

	public function end_class()
	{
		// $this->program->append_defer_check_identifiers($this->declaration);
		$this->class = null;
		$this->set_main_function();
	}

	public function begin_class_member(IClassMemberDeclaration $declaration)
	{
		$declaration->symbol = new Symbol($declaration);
		if (!$this->class->append_member($declaration)) {
			throw $this->parser->new_parse_error("Class member '{$declaration->name}' of '{$this->class->name}' has duplicated.");
		}

		if ($declaration instanceof FunctionDeclaration) {
			$this->block = $declaration;
			$this->enclosing = $declaration;
			$this->function = $declaration;
		}

		$declaration->super_block = $this->class;
		$this->declaration = $declaration;
	}

	public function end_class_member()
	{
		// $this->program->append_defer_check_identifiers($this->declaration);

		$this->class->append_defer_check_identifiers($this->declaration);

		$this->declaration = $this->class;
		$this->enclosing = null;
		$this->function = null;
	}

	public function begin_root_declaration(IRootDeclaration $declaration)
	{
		$this->declaration = $declaration;

		if ($declaration instanceof FunctionDeclaration) {
			$this->block = $declaration;
			$this->enclosing = $declaration;
			$this->function = $declaration;
		}
	}

	public function end_root_declaration()
	{
		// $this->program->append_defer_check_identifiers($this->declaration);
		$this->set_main_function();
	}

	public function begin_block(BaseBlock $block)
	{
		$block->super_block = $this->block;
		$this->block = $block;
	}

	public function end_block()
	{
		$block = $this->block;

		if ($block->super_block) {
			$this->block = $block->super_block;
			if ($block instanceof LambdaExpression) {
				$this->enclosing = $this->find_super_enclosing($block);
			}
		}
		else {
			$this->set_main_function();
		}

		return $block;
	}

	private function set_main_function()
	{
		$this->declaration = $this->function = $this->enclosing = $this->block = $this->program->main_function;
	}

	private static function find_super_enclosing(BaseBlock $block)
	{
		$block= $block->super_block;
		if (!$block || $block instanceof IEnclosingBlock) {
			return $block;
		}

		if (!$block instanceof BaseBlock) {
			return null;
		}

		return self::find_super_enclosing($block);
	}

	const GLOBAL_MODIFIERS = [_PUBLIC, _INTERNAL];
	private function check_global_modifier(?string $modifier, string $type_label)
	{
		if ($modifier && !in_array($modifier, self::GLOBAL_MODIFIERS, true)) {
			throw $this->parser->new_parse_error("Cannot use modifier '{$modifier}' for $type_label.");
		}
	}

	// use for include expression
	private function collect_created_symbols_in_current_function()
	{
		$block = $this->block;
		$symbols = $block->symbols;

		while (($block = $block->super_block) && !$block instanceof ClassLikeDeclaration) {
			$symbols = array_merge($symbols, $block->symbols);
		}

		if ($this->class) {
			$symbols[_THIS] = $this->class->this_object_symbol;
		}

		return $symbols;
	}

	private function seek_symbol_in_function(Identifiable $identifier, BaseBlock $seek_block = null)
	{
		if ($seek_block === null) {
			$seek_block = $this->block;
		}

		if (!$seek_block instanceof BaseBlock) {
			// should be at the begin of a class
			return $this->program->symbols[$identifier->name] ?? null;
		}

		$symbol = $this->seek_symbol_in_encolsing($identifier->name, $seek_block);

		if (!$symbol && $seek_block) {
			// add to lambda check list
			if ($seek_block instanceof LambdaExpression) {
				$seek_block->set_defer_check_identifier($identifier);
			}

			if ($seek_block->super_block && !$seek_block->super_block instanceof ClassLikeDeclaration) {
				$symbol = $this->seek_symbol_in_function($identifier, $seek_block->super_block);
			}
		}

		if ($symbol) {
			$identifier->symbol = $symbol;
		}

		return $symbol;
	}

	private function seek_symbol_in_encolsing(string $name, BaseBlock &$seek_block): ?Symbol
	{
		do {
			if (isset($seek_block->symbols[$name])) {
				return $seek_block->symbols[$name];
			}

			if ($seek_block instanceof IEnclosingBlock) {
				break;
			}
		} while ($seek_block = $seek_block->super_block);

		return null;
	}

	// create symbol, and add to current block
	private function create_local_symbol(IDeclaration $declaration)
	{
		$symbol = new Symbol($declaration);
		$this->add_block_symbol($symbol);

		return $symbol;
	}

	// // create symbol, and add to enclosing block, includes: Anonymous Function, Normal Function, Method
	// private function create_enclosing_symbol(IDeclaration $declaration)
	// {
	// 	$symbol = new Symbol($declaration);
	// 	$this->add_enclosing_symbol($symbol);

	// 	return $symbol;
	// }

	private function create_program_symbol(IDeclaration $declaration, Symbol $symbol = null)
	{
		if (!$symbol) {
			$symbol = new Symbol($declaration);
		}

		$this->add_program_symbol($symbol);

		return $symbol;
	}

	private function create_global_symbol(IDeclaration $declaration, Symbol $symbol = null)
	{
		$declaration->program = $this->program;

		if (!$symbol) {
			$symbol = new Symbol($declaration);
		}

		// it is useful for check
		// $declaration->symbol = $symbol;

		$this->add_program_symbol($symbol);
		$this->add_unit_symbol($symbol);

		return $symbol;
	}

	// private function create_global_symbol_with_namepath(array $namepath, IDeclaration $declaration)
	// {
	// 	$declaration->program = $this->program;
	// 	$symbol = new Symbol($declaration);

	// 	$ns_name = array_shift($namepath);
	// 	$ns_symbol = $this->unit->symbols[$ns_name] ?? null;

	// 	if ($ns_symbol) {
	// 		// maybe already used as a class
	// 		if (!$ns_symbol instanceof NamespaceSymbol) {
	// 			throw $this->parser->new_parse_error("'$ns_name' is already in use, cannot reuse to declare {$declaration->name}.");
	// 		}
	// 	}
	// 	else {
	// 		$ns_symbol = $this->create_symbol_for_ns($ns_name);
	// 		$this->add_unit_symbol($ns_symbol);
	// 	}

	// 	foreach ($namepath as $sub_ns_name) {
	// 		$ns_symbol = $this->find_or_create_subsymbol_for_ns($ns_symbol, $sub_ns_name);
	// 	}

	// 	// add as a sub-symbol for check
	// 	$ns_symbol->declaration->symbols[$symbol->name] = $symbol;

	// 	return $symbol;
	// }

	// private function create_symbol_for_ns(string $ns_name)
	// {
	// 	$declaration = new NamespaceDeclaration($ns_name);
	// 	return new NamespaceSymbol($declaration);
	// }

	// private function find_or_create_subsymbol_for_ns(NamespaceSymbol $super_symbol, string $sub_ns_name)
	// {
	// 	$sub_ns_symbol = $super_symbol->declaration->symbols[$sub_ns_name] ?? null;
	// 	if ($sub_ns_symbol) {
	// 		if (!$sub_ns_symbol instanceof NamespaceSymbol) {
	// 			throw $this->parser->new_parse_error("'$sub_ns_name' is already in use, cannot reuse to declare a namespace.");
	// 		}
	// 	}
	// 	else {
	// 		$sub_ns_symbol = $this->create_symbol_for_ns($sub_ns_name);
	// 		$super_symbol->declaration->symbols[$sub_ns_name] = $sub_ns_symbol;
	// 	}

	// 	return $sub_ns_symbol;
	// }

	private function add_unit_symbol(Symbol $symbol)
	{
		if (isset($this->unit->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use, cannot redeclare.");
		}

		$this->unit->symbols[$symbol->name] = $symbol;
	}

	private function add_program_symbol(Symbol $symbol)
	{
		if (isset($this->program->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in current program, cannot redeclare.");
		}

		$this->program->symbols[$symbol->name] = $symbol;
	}

	private function add_enclosing_symbol(Symbol $symbol)
	{
		if (isset($this->enclosing->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in enclosing function, cannot redeclare.");
		}

		$this->enclosing->symbols[$symbol->name] = $symbol;
	}

	private function add_block_symbol(Symbol $symbol)
	{
		if (isset($this->block->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in local block, cannot redeclare.");
		}

		$this->block->symbols[$symbol->name] = $symbol;
	}
}

// program end
