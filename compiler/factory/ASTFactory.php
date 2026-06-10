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
	public static object $default_value_mark;

	// for properties of Any type object in AccessingIdentifer
	// public static $virtual_property_for_any;

	public NamespaceIdentifier $root_namespace;

	public BaseParser $parser;

	private ?NamespaceIdentifier $ns = null;

	private ?Unit $unit = null;

	private ?Program $program = null;

	/**
	 * @var IDeclaration|null
	 */
	private $declaration;

	/**
	 * current classkindred
	 * @var ClassKindredDeclaration|null
	 */
	private $class;

	private array $class_context_stack = [];

	/**
	 * current function (includes closure)
	 * @var IFunctionDeclaration|null
	 */
	private $scope;

	/**
	 * @var IBlock|null
	 */
	private $block;

	// private $unit_path_symbol;

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
		$decl = new ConstantDeclaration(_PUBLIC, _UNIT_PATH, TypeFactory::$_string);
		// $this->unit_path_symbol = new Symbol($decl);
	}

	public function set_as_main()
	{
		$program = $this->require_program();
		$program->as_main = true;
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

	public function create_use_statement(NamespaceIdentifier $ns, array $targets, array $attributes = [])
	{
		// add to module uses
		$this->unit->use_units[$ns->uri] = $ns;
		if ($this->has_attribute($attributes, 'Trusted')) {
			$this->unit->trusted_use_units[$ns->uri] = true;
		}

		$statement = new UseStatement($ns, $targets, $attributes);
		$program = $this->require_program();
		$program->uses[] = $statement;

		// add namespace it self as a target when not has any targets
		if (!$targets) {
			$use = $this->append_use_target($ns);
		}

		return $statement;
	}

	private function has_attribute(array $attributes, string $name): bool
	{
		foreach ($attributes as $attribute) {
			if ($attribute instanceof MetaAttribute && $attribute->identifier->name === $name) {
				return true;
			}
		}

		return false;
	}

	public function append_use_target(NamespaceIdentifier $ns, ?string $target_name = null, ?string $source_name = null, ?string $import_kind = null)
	{
		$decl = new UseDeclaration($ns, $target_name, $source_name, $import_kind);
		$this->parser->attach_position($decl);

		if ($this->parser->is_parsing_header) {
			$this->create_internal_symbol($decl);
		}
		else {
			$this->create_program_symbol($decl);
		}

		// need to check
		$program = $this->require_program();
		$program->use_targets[] = $decl;

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

	public function create_goto_statement(string $target_label)
	{
		$statement = new GotoStatement($target_label);
		$statement->belong_block = $this->block;
		return $statement;
	}

	public function create_label_statement(string $label)
	{
		$statement = new LabelStatement($label);
		$statement->belong_block = $this->block;
		return $statement;
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

	public function create_type_reference(string $name)
	{
		$identifier = new TypeReference($name);
		$this->set_defer_check($identifier);
		return $identifier;
	}

	// public function create_class_type_identifier(string $name)
	// {
	// 	$identifier = new ClassType($name);
	// 	$this->set_defer_check($identifier);
	// 	return $identifier;
	// }

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
					$identifier->symbol = $this->is_declaration_static($decl)
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
			case _VAL_NULL:
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

	public function create_yield_expression(BaseExpression $argument, bool $is_from = false)
	{
		$function = $this->find_current_function_declaration();
		if ($function) {
			$function->set_declared_type(TypeFactory::$_generator);
		}

		// force set type Generator to current function
		// $this->function->declared_type = TypeFactory::$_generator;

		return new YieldExpression($argument, $is_from);
	}

	private function find_current_function_declaration(): ?IFunctionDeclaration
	{
		$block = $this->block;
		while ($block instanceof IBlock) {
			if ($block instanceof IFunctionDeclaration) {
				return $block;
			}

			$block = $block->get_belong_block();
		}

		return $this->declaration instanceof IFunctionDeclaration
			? $this->declaration
			: null;
	}

	public function create_throw_expression(BaseExpression $argument)
	{
		return new ThrowExpression($argument);
	}

	private function set_defer_check(Identifiable|TypeReference $identifier)
	{
		if (!$this->declaration instanceof IFunctionDeclaration or !$this->attach_local_symbol($identifier)) {
			$this->append_declaration_unknown_identifier($identifier);
		}
	}

	public function remove_defer_check(PlainIdentifier $identifier)
	{
		$block = $this->scope;
		$this->remove_unknown_identifier_from_block($block, $identifier);

		while ($block = $this->get_belong_block_of($block)) {
			if ($block instanceof IFunctionDeclaration) {
				$this->remove_unknown_identifier_from_block($block, $identifier);
			}
		}
	}

	private function append_declaration_unknown_identifier(Identifiable|TypeReference $identifier): void
	{
		$declaration = $this->declaration;
		if ($declaration instanceof IUnknownIdentifierContainer) {
			$declaration->append_unknow_identifier($identifier);
		}
	}

	private function is_declaration_static(?IDeclaration $declaration): bool
	{
		if ($declaration instanceof BaseClassMemberDeclaration) {
			return (bool)$declaration->is_static;
		}

		if ($declaration instanceof FunctionDeclaration) {
			return $declaration->is_static;
		}

		return false;
	}

	private function remove_unknown_identifier_from_block(object $block, PlainIdentifier|TypeReference $identifier): void
	{
		if ($block instanceof IUnknownIdentifierContainer) {
			$block->remove_unknow_identifier($identifier);
		}
	}

	private function get_belong_block_of(object $block): BaseDeclaration|IBlock|null
	{
		if ($block instanceof IBlock) {
			return $block->get_belong_block();
		}

		if ($block instanceof BaseDeclaration) {
			return $block->belong_block;
		}

		if ($block instanceof BaseStatement) {
			return $block->belong_block;
		}

		return null;
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
		if ($operator->is(OPID::IS) && $right instanceof VariableIdentifier) {
			$expr = new IsOperation($left, $right);
		}
		elseif ($operator->is(OPID::IS) && $right instanceof PlainIdentifier) {
			$expr = new IsOperation($left, $this->clone_type_reference_like($right));
		}
		elseif ($operator->is(OPID::IS) && $right instanceof TypeReference) {
			$expr = new IsOperation($left, $this->clone_type_reference_like($right));
		}
		else {
			$expr = new BinaryOperation($left, $right, $operator);
		}

		return $expr;
	}

	private function clone_type_reference_like(PlainIdentifier|TypeReference $identifier): TypeReference
	{
		$type = $this->create_type_reference($identifier->name);
		$type->ns = $identifier->ns;
		$type->pos = $identifier->pos;

		return $type;
	}

	public function create_assignment(BaseExpression $assigned_to, BaseExpression $value, Operator $operator)
	{
		if ($assigned_to instanceof PlainIdentifier) {
			$this->process_assigned_target($assigned_to, $value);
		}
		elseif ($assigned_to instanceof Destructuring) {
			// pass
		}
		elseif ($assigned_to instanceof BracketAccessing && ASTHelper::is_pure_bracket_accessing_expr($assigned_to)) {
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

	private function auto_declare_for_assigning_identifier(PlainIdentifier $identifier, ?BaseExpression $value)
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
		$program->source_dialect = $parser::SOURCE_DIALECT;

		// check name is in used
		if (isset($this->unit->programs[$program->name])) {
			throw new Exception("Error: Program name '{$program->name}' has been used, please rename the file '{$program->file}'");
		}

		$program->initializer = new FunctionDeclaration(_INTERNAL, '__main');
		$program->initializer->program = $program;

		$this->program = $program;
		$this->unit->programs[$program->name] = $program;

		$this->switch_to_initializer();

		return $program;
	}

	public function create_virtual_constant(string $name, ?BaseType $type = null, ?BaseExpression $value = null)
	{
		if ($type === null && $value === null) {
			$type = TypeFactory::$_mixed;
		}

		$decl = new ConstantDeclaration(_INTERNAL, $name, $type, $value);
		$decl->is_virtual = true;
		$symbol = new Symbol($decl);
		return [$decl, $symbol];
	}

	public function create_virtual_function(string $name, ?Program $program = null)
	{
		// $program and $program = $this->switch_program($program);

		$decl = new FunctionDeclaration(_INTERNAL, $name);
		$decl->is_virtual = true;
		$decl->infered_type = TypeFactory::$_mixed;

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

	public function create_virtual_class(string $name, ?Program $program = null)
	{
		// $program and $program = $this->switch_program($program);

		$decl = new ClassDeclaration(null, $name);
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
		$decl->infered_type = TypeFactory::$_mixed;
		$decl->parameters = [];

		$symbol = new Symbol($decl);
		$class->append_member_symbol($symbol);

		return [$decl, $symbol];
	}

	public function create_virtual_property(string $name, ?ClassKindredDeclaration $class)
	{
		$decl = new PropertyDeclaration(null, $name, TypeFactory::$_mixed);
		$decl->is_virtual = true;
		$decl->infered_type = TypeFactory::$_mixed;

		$symbol = new Symbol($decl);
		if ($class !== null) {
			$class->append_member_symbol($symbol);
		}

		return [$decl, $symbol];
	}

	public function create_virtual_class_constant(string $name, ?BaseType $type, ClassKindredDeclaration $class)
	{
		if ($type === null) {
			$type = TypeFactory::$_mixed;
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

	public function create_class_declaration(string $name, string $modifier, ?NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'class');

		$decl = new ClassDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);
		$this->bind_class_symbol($decl, $symbol);

		$this->begin_class($decl);

		return $decl;
	}

	public function create_enum_declaration(string $name, string $modifier, ?NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'enum');

		$decl = new EnumDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);
		$this->bind_class_symbol($decl, $symbol);

		$this->begin_class($decl);

		return $decl;
	}

	public function create_interface_declaration(string $name, string $modifier, ?NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'interface');

		$decl = new InterfaceDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);
		$this->bind_class_symbol($decl, $symbol);

		$this->begin_class($decl);

		return $decl;
	}

	public function create_intertrait_declaration(string $name, string $modifier, ?NamespaceIdentifier $ns = null)
	{
		$this->check_global_modifier($modifier, 'intertrait');

		$decl = new IntertraitDeclaration($modifier, $name);

		$symbol = $this->create_symbol_for_top_declaration($decl, $ns);
		$this->bind_class_symbol($decl, $symbol);

		$this->begin_class($decl);

		return $decl;
	}

	public function create_trait_declaration(string $name, string $modifier, ?NamespaceIdentifier $ns = null)
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
		$identifier = new TypeReference($decl->name); // as a Type for 'this'
		$identifier->symbol = $symbol;

		$decl->typing_identifier = $identifier;
		$decl->this_class_symbol = $symbol;
		$decl->this_object_symbol = self::create_symbol_this($identifier);

		// the MetaType
		$decl->declared_type = TypeFactory::create_meta_type($identifier);
	}

	private static function create_symbol_this(TypeReference $class)
	{
		$decl = new FinalVariableDeclaration(_THIS, $class);
		return new Symbol($decl);
	}

	public function create_traits_using_statement(array $items, array $options = [])
	{
		$decl = new TraitsUsingStatement($items, $options);
		$this->begin_class_member($decl);
		return $decl;
	}

	public function set_scope_parameters(array $parameters)
	{
		foreach ($parameters as $parameter) {
			$symbol = new Symbol($parameter);
			$this->add_scope_symbol($symbol);
		}

		$scope = $this->scope;
		if (!$scope instanceof IFunctionDeclaration) {
			throw new \LogicException('Scope context is not initialized.');
		}

		$scope->set_parameters($parameters);
	}

	public function create_member_mapping_declaration(string $name)
	{
		$decl = new MemberMappingDeclaration(_PUBLIC, $name);
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

	public function create_function_declaration(?string $modifier, string $name, ?NamespaceIdentifier $ns = null)
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

	public function create_enum_case_declaration(string $name)
	{
		$decl = new EnumCaseDeclaration($name);
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

	public function create_super_variable_declaration(string $name, BaseType $type)
	{
		$decl = new SuperVariableDeclaration($name, $type);

		$this->begin_root_declaration($decl);
		$this->create_internal_symbol($decl);

		return $decl;
	}

	public function create_variable_declaration(string $name, ?BaseType $type = null, ?BaseExpression $value = null)
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

	public function create_catch_block(?string $var_name, ?BaseType $type = null)
	{
		$var = $var_name === null ? null : new VariableDeclaration($var_name, $type);
		$block = new CatchBlock($var, $type);
		if ($var !== null) {
			$var->block = $block;
			$block->set_symbol($var_name, new Symbol($var));
		}
		$this->begin_block($block);

		return $block;
	}

	public function create_finally_block()
	{
		$block = new FinallyBlock();
		$this->begin_block($block);
		return $block;
	}

	public function create_match_block(BaseExpression $subject)
	{
		$block = new MatchBlock($subject);
		$this->begin_block($block);
		return $block;
	}

	public function create_match_arm(array $patterns)
	{
		$expr = new MatchArm($patterns);
		return $expr;
	}

	public function create_switch_block(BaseExpression $subject)
	{
		$block = new SwitchBlock($subject);
		$this->begin_block($block);
		return $block;
	}

	public function create_switch_branch(array $patterns)
	{
		$block = new SwitchBranch($patterns);
		$this->begin_block($block);
		return $block;
	}

	public function count_target_block_layers_with_label(string $label, string $block_interface)
	{
		$layers = 1;
		$switch_layers = 0;
		$block = $this->block;

		do {
			if ($block instanceof IFunctionDeclaration) {
				throw $this->parser->new_parse_error("Block of label '$label' not found in current function");
			}

			if ($block instanceof IBlock && $block->get_label() === $label) {
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

			$block = $this->get_belong_block_of($block);
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
			if ($block instanceof IFunctionDeclaration) {
				throw $this->parser->new_parse_error("Target block not found");
			}

			if (is_subclass_of($block, $block_interface)) {
				break;
			}
			elseif ($block instanceof SwitchBlock) {
				$switch_layers++;
			}

			$block = $this->get_belong_block_of($block);
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
		$this->block->set_symbol($name, $identifier->symbol);

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

	private function prepare_forin_vars(?ParameterDeclaration $key, ParameterDeclaration $val, BaseControlBlock $block)
	{
		if ($key) {
			// $key = new VariableDeclaration($key->name);
			$symbol = new Symbol($key);
			$block->set_symbol($key->name, $symbol);
			// $key->symbol = $symbol;
		}

		// $val = new VariableDeclaration($val->name);
		$symbol = new Symbol($val);
		$block->set_symbol($val->name, $symbol);
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

	// public function create_loop_block()
	// {
	// 	$block = new LoopBlock();
	// 	$this->begin_block($block);
	// 	return $block;
	// }

// --------

	public function end_branches(BaseControlBlock $node)
	{
		$symbols = $this->dig_intersected_symbols_for_block($node);
		$parent_block = $this->get_belong_block_of($node);
		if ($parent_block instanceof IBlock) {
			$this->add_symbols_to_block($parent_block, $symbols);
		}
	}

	public function end_mandatory_block(IBlock $node)
	{
		$parent_block = $this->get_belong_block_of($node);
		if ($parent_block instanceof IBlock) {
			$this->add_symbols_to_block($parent_block, $node->get_symbols());
		}
	}

	private function dig_intersected_symbols_for_block(BaseControlBlock $node)
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
				$symbols += $node->finally->get_symbols();
			}
		}

		return $symbols;
	}

	private function intersect_symbols_with_blocks(BaseControlBlock $block, array $branches)
	{
		$symbols = $block->get_symbols();
		foreach ($branches as $branch) {
			if (!$branch->has_transfered()) {
				$symbols = array_intersect_key($symbols, $branch->get_symbols());
			}
		}

		return $symbols;
	}

	private function add_symbols_to_block(IBlock $block, array $symbols)
	{
		foreach ($symbols as $key => $symbol) {
			if (!$block->has_symbol($key)) {
				$block->set_symbol($key, $symbol);
			}
		}
	}

	public function end_program()
	{
		// reset
		$this->program = null;
		$this->declaration = null;
		$this->scope = null;
		$this->block = null;
	}

	public function begin_class(ClassKindredDeclaration $decl)
	{
		$this->class_context_stack[] = [
			'class' => $this->class,
			'declaration' => $this->declaration,
			'scope' => $this->scope,
			'block' => $this->block,
		];

		$this->class = $decl;
		$this->declaration = $decl;
		$this->scope = null;
		$this->block = null;

		$program = $this->require_program();
		$program->append_declaration($decl);
	}

	public function end_class()
	{
		$context = array_pop($this->class_context_stack);
		if ($context === null) {
			$this->class = null;
			$this->switch_to_initializer();
			return;
		}

		$this->class = $context['class'];
		$this->declaration = $context['declaration'];
		$this->scope = $context['scope'];
		$this->block = $context['block'];
	}

	public function begin_class_member(IClassMemberDeclaration $decl)
	{
		if ($decl instanceof BaseDeclaration) {
			$decl->program = $this->program;
		}

		$symbol = new Symbol($decl);
		if (!$this->class->append_member_symbol($symbol)) {
			throw $this->parser->new_parse_error("Duplicated class member '{$decl->name}'");
		}

		if ($decl instanceof MethodDeclaration) {
			$this->scope = $decl;
		}

		$this->block = $decl;
		$this->declaration = $decl;
	}

	public function end_class_member()
	{
		$this->class->append_unknow_identifiers_from_declaration($this->declaration);

		$this->declaration = $this->class;
		$this->scope = null;
	}

	// For PHP 8.0+ constructor property promotion
	// Adds a member symbol to the current class without changing scope
	public function append_class_member_symbol(Symbol $symbol)
	{
		if (!$this->class->append_member_symbol($symbol)) {
			throw $this->parser->new_parse_error("Duplicated class member '{$symbol->name}'");
		}
	}

	public function begin_root_declaration(RootDeclaration $decl)
	{
		$this->declaration = $decl;

		if ($decl instanceof FunctionDeclaration) {
			$this->block = $decl;
			$this->scope = $decl;
		}

		$program = $this->require_program();
		$program->append_declaration($decl);
	}

	public function end_root_declaration()
	{
		$this->switch_to_initializer();
	}

	private function switch_to_initializer()
	{
		$program = $this->require_program();
		$this->declaration = $this->scope = $this->block = $program->initializer;
	}

	public function begin_block(IBlock $block)
	{
		$block->set_belong_block($this->block);
		$this->block = $block;
	}

	public function end_block()
	{
		$block = $this->block;

		$belong_block = $this->get_belong_block_of($block);
		if ($belong_block) {
			$this->block = $belong_block;
			if ($block instanceof AnonymousFunction) {
				$this->scope = $this->find_super_scope($block);
			}
		}

		return $block;
	}

	private static function find_super_scope(IBlock $block)
	{
		$block= $block->get_belong_block();
		if (!$block || $block instanceof IFunctionDeclaration) {
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

	private function attach_local_symbol(Identifiable|TypeReference $identifier, ?IBlock $seek_block = null)
	{
		if ($seek_block === null) {
			$seek_block = $this->block;
		}

		if (!$seek_block instanceof IBlock) {
			// should be at the begin of a class
			$program = $this->require_program();
			return $program->symbols[$identifier->name] ?? null;
		}

		$symbol = $this->seek_symbol_in_current_function($identifier->name, $seek_block);
		if ($symbol === null && $seek_block) {
			// add to lambda check list
			if ($seek_block instanceof AnonymousFunction) {
				$seek_block->append_unknow_identifier($identifier);
				// $identifier->lambda = $seek_block; // for the mutating feature
			}

			$belong_block = $this->get_belong_block_of($seek_block);
			if ($belong_block && !$belong_block instanceof ClassKindredDeclaration) {
				return $this->attach_local_symbol($identifier, $belong_block);
			}
		}

		$attached = false;
		if ($symbol) {
			$identifier->symbol = $symbol;
			$attached = true;
		}

		return $attached;
	}

	private function seek_symbol_in_current_function(string $name, IBlock &$seek_block): ?Symbol
	{
		do {
			if ($seek_block->has_symbol($name)) {
				return $seek_block->get_symbol($name);
			}

			if ($seek_block instanceof IFunctionDeclaration) {
				break;
			}
			$seek_block = $this->get_belong_block_of($seek_block);
		} while ($seek_block instanceof IBlock);

		return null;
	}

	// create symbol, and add to current block
	private function create_local_symbol(BaseDeclaration $decl)
	{
		$symbol = new Symbol($decl);
		$this->add_block_symbol($symbol);

		return $symbol;
	}

	private function new_top_symbol(BaseDeclaration $decl)
	{
		return new TopSymbol($decl);
	}

	private function create_program_symbol(BaseDeclaration $decl)
	{
		$symbol = $this->new_top_symbol($decl);
		$this->add_program_symbol($symbol);

		return $symbol;
	}

	private function create_internal_symbol(RootDeclaration $decl, ?Symbol $symbol = null)
	{
		$decl->program = $this->program;

		if ($symbol === null) {
			$symbol = $this->new_top_symbol($decl);
		}

		$this->add_program_symbol($symbol);
		$this->add_unit_symbol($symbol);

		return $symbol;
	}

	private function create_external_symbol(RootDeclaration $decl, NamespaceIdentifier $ns)
	{
		$decl->set_namespace($ns);
		$decl->program = $this->program;

		$symbol = $this->new_top_symbol($decl);

		$ns_decl = $this->find_or_create_namespace_declaration($decl->ns->names);
		if (isset($ns_decl->symbols[$symbol->name])) {
			if ($this->parser instanceof PHPParser) {
				return $ns_decl->symbols[$symbol->name];
			}

			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in namespace '{$decl->ns->uri}' of module '{$this->unit->name}'");
		}

		$ns_decl->symbols[$symbol->name] = $symbol;

		return $symbol;
	}

	private function create_symbol_for_top_declaration(RootDeclaration $decl, ?NamespaceIdentifier $ns)
	{
		$special_namespace = $ns ? ($this->ns === null || $ns->uri !== $this->ns->uri) : false;

		if ($special_namespace) {
			$symbol = $this->create_external_symbol($decl, $ns);
			if ($this->parser->is_declare_mode && $symbol->declaration === $decl) {
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
		$program = $this->require_program();
		if (isset($program->symbols[$symbol->name])) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in current program");
		}

		$program->symbols[$symbol->name] = $symbol;
	}

	private function require_program(): Program
	{
		$program = $this->program;
		if (!$program instanceof Program) {
			throw new \LogicException('Program context is not initialized.');
		}

		return $program;
	}

	private function add_scope_symbol(Symbol $symbol)
	{
		$scope = $this->scope;
		if (!$scope instanceof IFunctionDeclaration) {
			throw new \LogicException('Scope context is not initialized.');
		}

		if ($scope->has_symbol($symbol->name)) {
			throw $this->parser->new_parse_error("Symbol '{$symbol->name}' is already in use in current scope");
		}

		$scope->set_symbol($symbol->name, $symbol);
	}

	private function add_block_symbol(Symbol $symbol)
	{
		$block = $this->block;
		if (!$block instanceof IBlock) {
			throw new \LogicException('Block context is not initialized.');
		}

		$name = $symbol->name;
		if ($block->has_symbol($name)) {
			throw $this->parser->new_parse_error("Symbol '{$name}' is already in use in local block");
		}

		$block->set_symbol($name, $symbol);
	}
}

// program end
