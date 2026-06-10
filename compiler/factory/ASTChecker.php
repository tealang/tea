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

	private static array $checked_nodes = [];
	private static array $checking_nodes = [];
	private static array $type_assertions = [];
	private static array $preprocessed_classkindreds = [];

	/**
	 * current function
	 * @var IFunctionDeclaration
	 */
	private $function;

	/**
	 * @var array<string, LabelStatement>
	 */
	private array $goto_labels = [];

	/**
	 * current block
	 * @var IBlock
	 */
	private $block;

	private bool $allow_none_coalescing_key_access = false;
	private bool $allow_php_soft_key_access = false;

	private array $check_warnings = [];

	private static $builtin_checker_instance;
	private static $native_checker_instance;
	private static $normal_checker_instances = [];

	public static function reset_check_state(): void
	{
		self::$checked_nodes = [];
		self::$checking_nodes = [];
		self::$type_assertions = [];
		self::$preprocessed_classkindreds = [];
	}

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

	public static function init_checkers(Unit $main_unit, ?Unit $builtin_unit = null)
	{
		self::$normal_checker_instances = [];
		$main_unit->is_trusted = true;

		// for builtin unit
		if ($builtin_unit) {
			$builtin_unit->is_trusted = true;
			self::$normal_checker_instances[$builtin_unit->name] = new ASTChecker($builtin_unit);
		}

		$main_checker = new ASTChecker($main_unit, $builtin_unit);

		self::$builtin_checker_instance = $main_checker;
		self::$native_checker_instance = new PHPChecker($main_unit, $builtin_unit);

		self::$normal_checker_instances[$main_unit->name] = $main_checker;

		$seen = [$main_unit->path => true];
		self::init_checkers_for_used_units($main_unit, $builtin_unit, $seen);
	}

	/**
	 * @param array<string, bool> $seen
	 */
	private static function init_checkers_for_used_units(Unit $unit, ?Unit $builtin_unit, array &$seen): void
	{
		foreach ($unit->use_units as $dep_unit) {
			if (!$dep_unit instanceof Unit) {
				continue;
			}

			if (isset($seen[$dep_unit->path])) {
				continue;
			}

			$seen[$dep_unit->path] = true;
			self::init_checker_for_unit($dep_unit, $builtin_unit);
			self::init_checkers_for_used_units($dep_unit, $builtin_unit, $seen);
		}
	}

	private static function init_checker_for_unit(Unit $unit, ?Unit $builtin_unit)
	{
		if (!isset(self::$normal_checker_instances[$unit->name])) {
			self::$normal_checker_instances[$unit->name] = new ASTChecker($unit, $builtin_unit);
		}
	}

	public function __construct(Unit $unit, ?Unit $builtin_unit = null)
	{
		$this->unit = $unit;
		$this->builtin_unit = $builtin_unit;
		$this->factory = $unit->factory;
	}

	public function link_declarations(Program $program)
	{
		$this->set_program($program, __LINE__);

		foreach ($program->declarations as $decl) {
			$this->resolve_unknown_identifiers($decl);
			// if ($decl instanceof ClassKindredDeclaration) {
			// 	$this->is_classkindred_ready($decl) || $this->preprocess_classkindred_declaration($decl);
			// }
		}

		$program->initializer && $this->resolve_unknown_identifiers($program->initializer);
	}

	public function preprocess_declarations(Program $program)
	{
		$this->set_program($program, __LINE__);

		foreach ($program->declarations as $decl) {
			if ($decl instanceof ClassKindredDeclaration && !$this->is_classkindred_ready($decl)) {
				$this->preprocess_classkindred_declaration($decl);
			}
		}
	}

	private function set_program(Program $program, int $line)
	{
		$this->program = $program;
	}

	private function resolve_unknown_identifiers(BaseDeclaration $host_decl)
	{
		$temp_program = $this->program;
		$host_decl->program and $this->set_program($host_decl->program, __LINE__);

		foreach ($host_decl->unknow_identifiers as $identifier) {
			if ($this->get_symbol_for_symbolic_node($identifier) !== null) {
				// eg. identifiers that in foreach arguments
				continue;
			}

			$symbol = $this->find_symbol_for_plain_identifier($identifier);
			if ($symbol === null) {
				if ($this->is_weakly_checking) {
					if ($identifier instanceof VariableIdentifier) {
						// PHP variables, including superglobals missing from public headers, are dynamic.
						continue;
					}

					$symbol = $this->try_create_virtual_symbol($identifier);
				}
				else {
					throw $this->new_syntax_error("Symbol of identifier '{$identifier->name}' not found", $identifier);
				}
			}

			$depends = $symbol->declaration;
			if ($depends instanceof UseDeclaration) {
				$source = ASTHelper::get_use_source_declaration($depends);
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

			$this->set_symbol_for_symbolic_node($identifier, $symbol);
		}

		$host_decl->program and $this->set_program($temp_program, __LINE__);
	}

	private function try_create_virtual_symbol(PlainIdentifier|TypeReference $identifier)
	{
		$name = $identifier->name;
		if ($identifier instanceof BaseExpression) {
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
		}
		elseif ($identifier instanceof TypeReference) {
			[$decl, $symbol] = $this->factory->create_virtual_class($name, $this->program);
		}
		else {
			throw $this->new_syntax_error("Symbol of '{$identifier->name}' not found", $identifier);
		}

		if (($identifier instanceof PlainIdentifier || $identifier instanceof TypeReference)
			&& $identifier->ns !== null
			&& $decl instanceof ClassKindredDeclaration
			&& $decl->typing_identifier !== null) {
			$decl->typing_identifier->set_namespace($identifier->ns);
		}

		return $symbol;
	}

	public function check_program(Program $program)
	{
		if ($this->is_checked($program)) return;
		$this->mark_checked($program);

		$this->set_program($program, __LINE__);

		foreach ($program->declarations as $node) {
			$this->check_declaration($node);
		}

		if ($program->initializer) {
			$this->check_declaration($program->initializer);
		}
	}

	private function is_checked(object $node): bool
	{
		$key = spl_object_id($node);
		return isset(self::$checked_nodes[$key]) && self::$checked_nodes[$key] === $node;
	}

	private function mark_checked(object $node): void
	{
		self::$checked_nodes[spl_object_id($node)] = $node;
	}

	private function is_classkindred_ready(ClassKindredDeclaration $node): bool
	{
		$key = spl_object_id($node);
		return isset(self::$preprocessed_classkindreds[$key]) && self::$preprocessed_classkindreds[$key] === $node;
	}

	private function mark_classkindred_ready(ClassKindredDeclaration $node): void
	{
		self::$preprocessed_classkindreds[spl_object_id($node)] = $node;
	}

	private function is_checking(object $node): bool
	{
		$key = spl_object_id($node);
		return isset(self::$checking_nodes[$key]) && self::$checking_nodes[$key] === $node;
	}

	private function mark_checking(object $node): void
	{
		self::$checking_nodes[spl_object_id($node)] = $node;
	}

	private function get_type_assertion_for(BaseExpression $node): ?IsOperation
	{
		$key = spl_object_id($node);
		$entry = self::$type_assertions[$key] ?? null;
		if ($entry === null || $entry[0] !== $node) {
			return null;
		}

		return $entry[1];
	}

	private function set_type_assertion_for(BaseExpression $node, ?IsOperation $assertion): void
	{
		$key = spl_object_id($node);
		if ($assertion === null) {
			unset(self::$type_assertions[$key]);
			return;
		}

		self::$type_assertions[$key] = [$node, $assertion];
	}

	public function check_all_usings(Program $program)
	{
		$this->set_program($program, __LINE__);
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
		elseif ($this->try_link_source_declaration_for_same_unit_use($node)) {
			return;
		}
		elseif (!$this->is_weakly_checking) {
			throw $this->new_syntax_error("Package '{$node->ns->uri}' not found", $node->ns);
		}
	}

	private function try_link_source_declaration_for_same_unit_use(UseDeclaration $node): bool
	{
		$name = $node->source_name ?? $node->target_name;
		if ($name === null) {
			return false;
		}

		$symbol = $node->ns->is_global_space()
			? ($this->get_symbol_in_unit($this->unit, $name)
				?? ($this->builtin_unit ? $this->get_symbol_in_unit($this->builtin_unit, $name) : null))
			: ($this->find_symbol_in_namespace($node->ns, $name, $this->unit)
				?? ($this->builtin_unit ? $this->find_symbol_in_namespace($node->ns, $name, $this->builtin_unit) : null));
		if ($symbol === null) {
			return false;
		}

		$target_declaration = $symbol->declaration;
		if ($node->import_kind !== null) {
			$this->check_use_import_kind($node, $target_declaration);
		}

		ASTHelper::set_use_source_declaration($node, $target_declaration);
		$this->mark_checked($node);

		return true;
	}

	private function check_declaration(BaseDeclaration $decl)
	{
		if ($this->is_checked($decl)) {
			return $decl;
		}

		$decl_program = $decl->program ?? null;
		$temp_program = $this->program;
		$decl_program and $this->set_program($decl_program, __LINE__);

		switch ($decl::KIND) {
			case FunctionDeclaration::KIND:
				$this->check_function_declaration($decl);
				break;

			case BuiltinTypeClassDeclaration::KIND:
			case ClassDeclaration::KIND:
			case EnumDeclaration::KIND:
			case IntertraitDeclaration::KIND:
			case TraitDeclaration::KIND:
			case InterfaceDeclaration::KIND:
				$this->is_checked($decl) || $this->check_classkindred_declaration($decl);
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

		$decl_program and $this->set_program($temp_program, __LINE__);
	}

	private function check_class_member_declaration(IClassMemberDeclaration $node)
	{
		if ($node instanceof MemberMappingDeclaration) {
			$this->check_member_mapping_declaration($node);
		}
		elseif ($node instanceof MethodDeclaration) {
			$this->check_method_declaration($node);
		}
		elseif ($node instanceof PropertyDeclaration || $node instanceof ObjectMember) {
			$this->check_property_declaration($node);
		}
		elseif ($node instanceof ClassConstantDeclaration) {
			$this->check_class_constant_declaration($node);
		}
		elseif ($node instanceof EnumCaseDeclaration) {
			$this->check_enum_case_declaration($node);
		}
		elseif ($node instanceof TraitsUsingStatement) {
			// Trait use statement - adaptations checked during parsing
			// Add to class usings so trait members can be aggregated
			$class_decl = $node->belong_block;
			if ($class_decl instanceof ClassKindredDeclaration) {
				$class_decl->append_trait_using($node);
			}
			// Link symbols for trait identifiers
			foreach ($node->items as $item) {
				if ($item instanceof \Tea\TypeReference && TypeHelper::get_type_symbol($item) === null) {
					TypeHelper::set_type_symbol($item, $this->find_symbol_for_plain_identifier($item));
				}
			}
		}
		else {
			$kind = get_class($node);
			throw $this->new_syntax_error("Unexpect class/interface member declaration kind: '{$kind}'", $node);
		}
	}

	private function get_and_check_hinted_type(IDeclaration|AnonymousFunction|CallableType $node)
	{
		$noted = ASTHelper::get_noted_type($node);
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
		if ($this->is_checked($node)) return;
		$this->mark_checked($node);

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

	private function assert_compile_time_value_for(IValuableDeclaration $node)
	{
		$value = $node->value;

		// PHP native path is weakly checked; allow runtime-acceptable initializers
		// that are not yet fully modeled by Tea compile-time const expression rules.
		if ($this->is_weakly_checking) {
			return;
		}

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
			$decl = $this->get_symbol_for_symbolic_node($node)->declaration;
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

	private function check_variable_declaration(IVariableDeclaration $node)
	{
		$this->mark_checked($node);

		$infered = $node->value ? $this->infer_expression($node->value) : null;

		$this->set_variable_kindred_declaration_type($node, $infered);
		if ($node instanceof VariableDeclaration && $node->value !== null) {
			TypeHelper::set_bound_value($node, $node->value);
		}
		$this->bind_variadic_parameter_array_type($node);
	}

	private function set_variable_kindred_declaration_type(BaseDeclaration $node, ?BaseType $infered)
	{
		$hinted = $this->get_and_check_hinted_type($node);
		if ($hinted) {
			if ($infered) {
				if ($node->value instanceof LiteralDefaultMark) {
					//
				}
				elseif ($this->is_nullable_default_allowed_by_declared_type($node, $infered)) {
					//
				}
				elseif ($this->is_phpdoc_null_default_allowed($node, $infered)) {
					//
				}
				else {
					$this->assert_type_compatible($hinted, $infered, $node->value);
				}
			}

			// use the hinted as infered, because is declaration
			$infered = $hinted;
		}
		elseif ($infered === TypeFactory::$_uint && $node->value instanceof LiteralInteger) {
			// set infered type to Int when value is Integer literal
			// $infered = TypeFactory::$_int;
		}
		elseif ($infered instanceof NoneType) {
			$infered = $this->get_unknown_php_value_type();
		}
		// elseif ($infered === null || $infered instanceof NoneType) {
		// 	$infered = TypeFactory::$_any;
		// }

		$node->infered_type = $infered;
	}

	private function is_nullable_default_allowed_by_declared_type(BaseDeclaration $node, BaseType $infered): bool
	{
		return $infered instanceof NoneType
			&& $node->declared_type instanceof BaseType
			&& TypeHelper::is_nullable_type($node->declared_type);
	}

	private function is_phpdoc_null_default_allowed(BaseDeclaration $node, BaseType $infered): bool
	{
		return $this->is_weakly_checking
			&& $infered instanceof NoneType
			&& $node->declared_type === null
			&& ASTHelper::is_noted_type_from_phpdoc($node);
	}

	private function bind_variadic_parameter_array_type(IVariableDeclaration $node): void
	{
		if (!$node instanceof ParameterDeclaration || !$node->is_variadic) {
			return;
		}

		$element_type = ASTHelper::get_noted_type($node) ?? $node->declared_type ?? $this->get_unknown_php_value_type();
		$node->bind_type(TypeFactory::create_array_type($element_type));
	}

	private function check_parameters_for_callable_declaration(ICallableDeclaration $node)
	{
		foreach ($node->parameters as $parameter) {
			$this->check_variable_declaration($parameter);
			if ($parameter->value !== null) {
				$this->assert_compile_time_value_for($parameter);
			}
		}
	}

	private function check_member_mapping_declaration(MemberMappingDeclaration $node)
	{
		$node->parameters && $this->check_parameters_for_callable_declaration($node);
		$mapping_body = $node->body;

		// maybe need render, so check first
		$temp_block = $this->block;
		$this->block = $node;
		$infered = $this->infer_expression($mapping_body);
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

		if ($mapping_body instanceof CallExpression) {
			$this->process_member_mapping($node);
		}
		else {
			// the this, do not need any process
		}
	}

	private function process_member_mapping(MemberMappingDeclaration $node)
	{
		$i = 0;
		$parameters_map = [_THIS => $i++];

		$parameters = $node->parameters ?? [];

		foreach ($parameters as $item) {
			$parameters_map[$item->name] = $i++;
		}

		$mapping_body = $node->body;
		foreach ($mapping_body->arguments as $dest_idx => $item) {
			if ($item instanceof PlainIdentifier) {
				if (!isset($parameters_map[$item->name])) {
					$this->infer_plain_identifier($item);
					if (ASTHelper::get_identifier_symbol($item)->declaration instanceof ConstantDeclaration) {
						$node->arguments_map[$dest_idx] = $item;
						continue;
					}

					throw $this->new_syntax_error("Identifier '{$item->name}' not defined in member mapping declaration", $item);
				}

				// the argument from member mapping call
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
				throw $this->new_syntax_error("Unexpect expression in member mapping declaration", $item);
			}
		}
	}

	private function infer_anonymous_function(AnonymousFunction $node)
	{
		// check for use variables
		foreach ($node->unknow_identifiers as $identifier) {
			if (!$this->get_symbol_for_symbolic_node($identifier)) {
				if ($identifier instanceof PlainIdentifier) {
					$this->infer_plain_identifier($identifier);
				}
				elseif ($identifier instanceof TypeReference) {
					TypeHelper::set_type_symbol($identifier, $this->find_symbol_for_plain_identifier($identifier));
				}
			}

			if ($this->get_symbol_for_symbolic_node($identifier)->declaration instanceof IVariableDeclaration) {
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

		return TypeFactory::create_callable_type($node->get_expressed_type(), $node->parameters);
	}

	private function check_function_kindred(IFunctionDeclaration $node)
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

	private function infer_function_body(IFunctionDeclaration $node, ?BaseType $hinted)
	{
		$prev_func = $this->function;
		$this->function = $node; // for find 'super' in methods

		try {
			if (is_array($node->body)) {
				$prev_goto_labels = $this->goto_labels;
				$this->goto_labels = $this->collect_goto_labels($node);
				try {
					$infered = $this->infer_block($node);
				}
				finally {
					$this->goto_labels = $prev_goto_labels;
				}
			}
			else {
				$infered = $this->infer_single_expression_block($node);
			}

			if ($hinted) {
				if ($infered !== null) {
					if (!$this->is_type_compatible($hinted, $infered, $node, 'return') && $this->should_report_type_mismatch($hinted, $infered, $node, 'return')) {
						if ($node instanceof BaseDeclaration && $this->warn_phpdoc_only_type_mismatch($node, $hinted, $infered, $node, 'return')) {
							return $infered;
						}

						$infered_name = self::get_type_name($infered);
						$hinted_name = self::get_type_name($hinted);
						throw $this->new_syntax_error("The infered return type '{$infered_name}' is incompatible with the hinted '{$hinted_name}'", $node);
					}
				}
				elseif (!TypeHelper::is_same_type($hinted, TypeFactory::$_void)
					&& !TypeHelper::is_same_or_based_type($hinted, TypeFactory::$_generator)
					&& !$this->block_ends_with_control_transfer($node)) {
					throw $this->new_syntax_error("Function required a return type '{$hinted->name}'", $node);
				}
			}
		}
		finally {
			$this->function = $prev_func;
		}

		return $infered;
	}

	private function block_ends_with_control_transfer(IBlock $block): bool
	{
		if (!is_array($block->body) || $block->body === []) {
			return false;
		}

		$last_statement = $block->body[array_key_last($block->body)];
		return $last_statement instanceof ReturnStatement
			|| $last_statement instanceof ThrowStatement
			|| $last_statement instanceof ExitStatement;
	}

	protected function check_function_declaration(FunctionDeclaration $node)
	{
		if ($this->is_checked($node)) return;
		$this->mark_checked($node);

		$this->check_function_kindred($node);
	}


	protected function check_method_declaration(MethodDeclaration $node)
	{
		if ($this->is_checked($node)) return;
		$this->mark_checked($node);

		$member_bound_types = $this->snapshot_class_member_bound_types($node);
		$is_top_level_check = $this->function === null;
		$this->clear_class_member_bound_types($member_bound_types);

		try {
			$this->check_function_kindred($node);
		}
		finally {
			if ($is_top_level_check) {
				$this->clear_class_member_bound_types($member_bound_types);
			}
			else {
				$this->restore_class_member_bound_types($member_bound_types);
			}
		}
	}

	private function snapshot_class_member_bound_types(MethodDeclaration $node): array
	{
		$class_decl = $node->belong_block;
		if (!$class_decl instanceof ClassKindredDeclaration) {
			return [];
		}

		$snapshots = [];
		$seen = [];
		foreach ($class_decl->members as $member) {
			$this->append_variable_bound_type_snapshot($member, $snapshots, $seen);
		}

		foreach (ASTHelper::get_aggregated_members($class_decl) as $symbol) {
			$this->append_variable_bound_type_snapshot($symbol->declaration, $snapshots, $seen);
		}

		return $snapshots;
	}

	private function append_variable_bound_type_snapshot($decl, array &$snapshots, array &$seen): void
	{
		if (!$decl instanceof IVariableDeclaration) {
			return;
		}

		$id = spl_object_id($decl);
		if (isset($seen[$id])) {
			return;
		}

		$seen[$id] = true;
		$snapshots[] = [$decl, TypeHelper::get_raw_bound_type($decl)];
	}

	private function restore_class_member_bound_types(array $snapshots): void
	{
		foreach ($snapshots as [$decl, $bound_type]) {
			TypeHelper::set_raw_bound_type($decl, $bound_type);
		}
	}

	private function clear_class_member_bound_types(array $snapshots): void
	{
		foreach ($snapshots as [$decl]) {
			TypeHelper::set_raw_bound_type($decl, null);
		}
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

	private function check_enum_case_declaration(EnumCaseDeclaration $node)
	{
		if ($node->value !== null) {
			$infered = $this->infer_expression($node->value);
			$this->assert_compile_time_value_for($node);
		}

		$node->infered_type = $node->belong_block->typing_identifier;
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

		$node->infered_type = $infered ?? $hinted ?? $this->get_unknown_php_value_type();
	}

	private function preprocess_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$temp_program = $this->program;
		$node->program and $this->set_program($node->program, __LINE__);

		// $this->resolve_unknown_identifiers($node);

		// First, collect trait usings from members
		foreach ($node->members as $member) {
			if ($member instanceof TraitsUsingStatement) {
				$node->append_trait_using($member);
			}
		}

		// when it is currently a class, including inherited classes or implemented interfaces
		// when it is currently an interface, including inherited interfaces
		if ($node->extends) {
			$this->preprocess_bases_for_classkindred_declaration($node);
		}

		ASTHelper::set_trait_members($node, $this->dig_trait_members_for($node));

		ASTHelper::set_aggregated_members($node, array_merge(ASTHelper::get_trait_members($node), ASTHelper::get_scope_symbols($node)));

		if ($node->extends) {
			$digged = $this->dig_members_in_extends_for($node);
			ASTHelper::set_aggregated_members($node, array_merge($digged, ASTHelper::get_aggregated_members($node)));
		}

		if ($node->implements) {
			$digged = $this->dig_members_in_implements_for($node);
			ASTHelper::set_aggregated_members($node, array_merge($digged, ASTHelper::get_aggregated_members($node)));
		}

		// the members in this class have the highest priority
		ASTHelper::set_aggregated_members($node, array_merge(ASTHelper::get_aggregated_members($node), ASTHelper::get_scope_symbols($node)));

		$this->mark_classkindred_ready($node);

		$this->set_program($temp_program, __LINE__);
	}

	private function check_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$this->is_classkindred_ready($node) or $this->preprocess_classkindred_declaration($node);

		$this->mark_checked($node);

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

			foreach ($node->implements as $identifier) {
				$based_decl = $this->get_unchecked_classkindred_declaration($identifier);
				if (!$based_decl instanceof InterfaceDeclaration) {
					throw $this->new_syntax_error("Cannot implements to a class '{$identifier->name}'", $identifier);
				}

				$interface_identifiers[] = $identifier;
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

				if (!$this->is_classkindred_ready($decl)) {
					$this->preprocess_classkindred_declaration($decl);
				}

				$node->unite_feature_flags($decl->feature_flags);
				$items = array_merge($items, ASTHelper::get_trait_members($decl));
				foreach (ASTHelper::get_aggregated_members($decl) as $name => $member_symbol) {
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
			$items = array_merge($items, ASTHelper::get_aggregated_members($based_decl));
		}

		// check if there are overridden members in this class that match the parent class members
		foreach ($items as $name => $super_member_symbol) {
			$current_member_symbol = ASTHelper::get_aggregated_members($node)[$name] ?? null;
			if ($current_member_symbol) {
				// check super class member declared in current class
				$super_member = $super_member_symbol->declaration;
				$current_member = $current_member_symbol->declaration;
				if ($this->separate_php_property_method_namespace($node, $items, $name, $current_member_symbol, $super_member_symbol)) {
					continue;
				}

				$this->check_overrided_member($current_member, $super_member);
			}
		}

		return $items;
	}

	private function separate_php_property_method_namespace(ClassKindredDeclaration $node, array &$items, string $name, Symbol $current_member_symbol, Symbol $super_member_symbol): bool
	{
		$current_member = $current_member_symbol->declaration;
		$super_member = $super_member_symbol->declaration;
		if ($current_member instanceof PropertyDeclaration && $super_member instanceof MethodDeclaration) {
			$property_key = ClassKindredDeclaration::get_property_symbol_key($current_member->name);
			$members = ASTHelper::get_aggregated_members($node);
			$members[$property_key] = $current_member_symbol;
			unset($members[$name], $node->members[$name]);
			ASTHelper::unset_scope_symbol($node, $name);
			ASTHelper::set_scope_symbol($node, $property_key, $current_member_symbol);
			$node->members[$property_key] = $current_member;
			ASTHelper::set_aggregated_members($node, $members);
			return true;
		}

		if ($current_member instanceof MethodDeclaration && $super_member instanceof PropertyDeclaration) {
			$property_key = ClassKindredDeclaration::get_property_symbol_key($super_member->name);
			$items[$property_key] = $super_member_symbol;
			unset($items[$name]);
			return true;
		}

		return false;
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

			foreach (ASTHelper::get_aggregated_members($based_decl) as $name => $member_symbol) {
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
				$current_member_symbol = ASTHelper::get_aggregated_members($node)[$name] ?? null;
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
				throw $this->new_syntax_error("Member declaration kind of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$super->belong_block->name}.{$super->name}'", $node);
			}

			$this->check_overrided_method_parameters($node, $super);
			$covariance_mode = true;
		}
		elseif ($super instanceof PropertyDeclaration) {
			if (!$node instanceof PropertyDeclaration) {
				throw $this->new_syntax_error("Member declaration kind of '{$node->belong_block->name}.{$node->name}' is incompatible with '{$super->belong_block->name}.{$super->name}'", $node);
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

			if ($this->is_weakly_checking && $covariance_mode) {
				return;
			}

			$current_type_name = $this->get_type_name($current_type);
			throw $this->new_syntax_error("There are declared type '{$current_type_name}' in '{$node->belong_block->name}', but not declared in '{$super->belong_block->name}'", $node);
		}

		if ($current_type === null) {
			$super_type_name = $this->get_type_name($super_type);
			throw $this->new_syntax_error("There are not declared type in '{$node->belong_block->name}', but declared '{$super_type_name}' in '{$super->belong_block->name}'", $node);
		}

		$current_type = $this->rebind_self_type_for_member_declared_type($current_type, $node);
		$super_type = $this->rebind_self_type_for_member_declared_type($super_type, $super);

		// supports Covariance
		$compatible = $covariance_mode
			? TypeHelper::is_covariant_for($current_type, $super_type)
			: $current_type->is_same_with($super_type);
		if (!$compatible) {
			$current_type_name = $this->get_type_name($current_type);
			$super_type_name = $this->get_type_name($super_type);
			throw $this->new_syntax_error("The declared type '{$current_type_name}' in '{$node->belong_block->name}' is incompatible with '$super_type_name' in '{$super->belong_block->name}'", $node);
		}
	}

	private function rebind_self_type_for_member_declared_type(BaseType $type, IClassMemberDeclaration $member): BaseType
	{
		if (!$this->contains_self_type($type) || !$member->belong_block instanceof ClassKindredDeclaration) {
			return $type;
		}

		return $this->replace_self_type($type, $member->belong_block->typing_identifier);
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
			$super_type = $this->get_override_parameter_hinted_type($protocol_param, $protocol);
			$current_type = $this->get_override_parameter_hinted_type($node_param, $node);
			if (!$this->is_overrided_method_param_compatible_types($super_type, $current_type)) {
				$type_name = $this->get_type_name($current_type);
				throw $this->new_syntax_error("Parameter '{$node_param->name} {$type_name}' in '{$node->belong_block->name}.{$node->name}' is incompatible with '{$protocol->belong_block->name}.{$protocol->name}'", $node_param);
			}
		}
	}

	private function get_override_parameter_hinted_type(ParameterDeclaration $node, IFunctionDeclaration $method): BaseType
	{
		$type = ASTHelper::is_noted_type_from_phpdoc($node) && $node->declared_type !== null
			? $node->declared_type
			: $node->get_hinted_type();

		if (($type instanceof SelfType || $type->name === _TYPE_SELF)
			&& $method->belong_block instanceof ClassKindredDeclaration) {
			return $method->belong_block->typing_identifier;
		}

		return $type;
	}

	private function is_overrided_method_param_compatible_types(BaseType $super, BaseType $current)
	{
		return TypeHelper::is_override_parameter_compatible($super, $current, $this->is_weakly_checking);
	}

	private function infer_if_block(IfBlock $node): ?BaseType
	{
		$result_type = $this->infer_base_if_block($node);

		return $result_type;
	}

	private function infer_base_if_block(BaseIfBlock $node): ?BaseType
	{
		$condition = $node->condition;
		$this->infer_expression($condition);

		[$empty_argument, $empty_is_not] = $this->get_empty_condition_target($condition);
		if ($empty_argument && $this->can_assert_nullable_identifiable($empty_argument)) {
			return $this->infer_base_if_block_with_empty_guard($node, $empty_argument, $empty_is_not);
		}

		[$assertion, $is_not] = $this->get_type_assertion($condition);
		if ($assertion) {
			// with type assertion
			$result_type = $this->infer_base_if_block_with_assertion($node, $assertion, $is_not);
		}
		else {
			// without type assertion
			$parent_bindings = $this->snapshot_variable_bindings($this->block);
			$result_type = $this->infer_block($node);
			if ($this->is_weakly_checking) {
				$main_rebound_vars = $node->get_rebound_variables();
				$main_rebound_types = $node->reset_rebound_types();
				if ($node->else) {
					$result_type = $this->reduce_types_with_else_block($node, $result_type);
					$this->deliver_bound_types_with_else($node->else, $main_rebound_vars, $main_rebound_types);
				}
				elseif (!$this->block_exits_current_flow_for_narrowing($node)) {
					$this->deliver_bound_types_without_else($main_rebound_vars, $main_rebound_types);
				}
				else {
					$this->restore_variable_bindings($parent_bindings);
					$this->apply_condition_assertions_after_exited_if($condition);
				}
			}
			else {
				if ($node->else) {
					$result_type = $this->reduce_types_with_else_block($node, $result_type);
				}
				elseif ($this->block_exits_current_flow_for_narrowing($node)) {
					$this->restore_variable_bindings($parent_bindings);
					$this->apply_condition_assertions_after_exited_if($condition);
				}
			}
		}

		return $result_type;
	}

	private function apply_condition_assertions_after_exited_if(BaseExpression $condition): void
	{
		$bindings = [];
		$this->apply_condition_assertions($condition, false, $bindings);
	}

	private function get_type_assertion(BaseExpression $expr)
	{
		$is_not = false;
		if ($expr instanceof PrefixOperation and $expr->operator->is(OPID::BOOL_NOT)) {
			$is_not = true;
			$expr = $expr->expression;
		}

		$assertion = null;
		if ($expr instanceof IsOperation && $expr->right instanceof BaseType) {
			$assertion = $expr;
		}
		elseif ($expr instanceof BinaryOperation) {
			$assertion = $this->get_type_assertion_for($expr);
		}
		elseif ($expr instanceof BaseCallExpression) {
			$assertion = $this->get_isset_type_assertion($expr);
			$assertion ??= $this->get_declared_php_type_assertion($expr);
			$assertion ??= $this->get_php_builtin_fallback_type_assertion($expr);
			$assertion ??= $this->get_php_predicate_type_assertion($expr);
		}
		elseif ($expr instanceof Identifiable && $this->can_assert_nullable_identifiable($expr)) {
			$assertion = new IsOperation($expr, TypeFactory::$_none, true);
		}

		if ($assertion and $assertion->not) {
			$is_not = !$is_not;
		}

		return [$assertion, $is_not];
	}

	private function infer_base_if_block_with_empty_guard(BaseIfBlock $node, Identifiable $argument, bool $is_not): ?BaseType
	{
		$argument_decl = ASTHelper::get_identifier_symbol($argument)->declaration;
		$argument_type = $this->get_assertion_original_type($argument_decl);
		$non_empty_type = TypeHelper::to_non_nullable($argument_type);

		if ($is_not) {
			$argument_decl->bind_type($non_empty_type);
		}

		$result_type = $this->infer_block($node);
		$main_rebound_vars = $node->get_rebound_variables();
		$main_rebound_types = $node->reset_rebound_types();

		if ($node->else) {
			$argument_decl->bind_type($is_not ? $argument_type : $non_empty_type);
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
			$this->deliver_bound_types_with_else($node->else, $main_rebound_vars, $main_rebound_types, $argument_decl);
			$argument_decl->bind_type($argument_type);
		}
		elseif (!$is_not && $this->block_exits_current_flow_for_narrowing($node)) {
			$argument_decl->bind_type($non_empty_type);
		}
		else {
			$argument_decl->bind_type($argument_type);
			$this->deliver_bound_types_without_else($main_rebound_vars, $main_rebound_types);
		}

		return $result_type;
	}

	private function get_empty_condition_target(BaseExpression $expr): array
	{
		$is_not = false;
		if ($expr instanceof PrefixOperation && $expr->operator->is(OPID::BOOL_NOT)) {
			$is_not = true;
			$expr = $expr->expression;
		}

		if (!$expr instanceof BaseCallExpression) {
			return [null, false];
		}

		return [$this->get_single_identifiable_argument_for($expr, 'empty'), $is_not];
	}

	private function get_isset_type_assertion(BaseCallExpression $expr): ?IsOperation
	{
		$argument = $this->get_single_identifiable_argument_for($expr, 'isset');
		if (!$argument || !$this->can_assert_nullable_identifiable($argument)) {
			return null;
		}

		return new IsOperation($argument, TypeFactory::$_none, true);
	}

	private function get_single_identifiable_argument_for(BaseCallExpression $expr, string $callee_name): ?Identifiable
	{
		if (count($expr->arguments) !== 1 || $expr->named_arguments) {
			return null;
		}

		if ($this->get_plain_callee_name($expr->callee) !== $callee_name) {
			return null;
		}

		$argument = $expr->arguments[0];
		return $argument instanceof Identifiable ? $argument : null;
	}

	private function can_assert_nullable_identifiable(Identifiable $argument): bool
	{
		$argument_decl = ASTHelper::get_identifier_symbol($argument)->declaration ?? null;
		if (!$argument_decl instanceof IVariableDeclaration) {
			return false;
		}

		$argument_type = $this->get_assertion_original_type($argument_decl);
		return !$argument_type instanceof AnyType && TypeHelper::is_nullable_type($argument_type);
	}

	private function get_php_builtin_fallback_type_assertion(BaseCallExpression $expr): ?IsOperation
	{
		if (!$this->is_weakly_checking || $this->builtin_unit !== null || count($expr->arguments) !== 1 || $expr->named_arguments) {
			return null;
		}

		$type = TypeHelper::get_php_builtin_predicate_asserted_type_fallback($this->get_plain_callee_name($expr->callee));

		return $type ? new IsOperation($expr->arguments[0], $type) : null;
	}

	private function get_declared_php_type_assertion(BaseCallExpression $expr): ?IsOperation
	{
		if (!$this->is_weakly_checking || $expr->named_arguments) {
			return null;
		}

		$callee_decl = ASTHelper::get_callee_declaration($expr);
		if (!$callee_decl instanceof IFunctionDeclaration) {
			return null;
		}

		foreach (ASTHelper::get_php_true_assertions($callee_decl) as $index => $type) {
			if (isset($expr->arguments[$index])) {
				return new IsOperation($expr->arguments[$index], $type);
			}
		}

		$property_assertion = $this->get_declared_php_property_type_assertion($expr, $callee_decl);
		if ($property_assertion !== null) {
			return $property_assertion;
		}

		return null;
	}

	private function get_declared_php_property_type_assertion(BaseCallExpression $expr, IFunctionDeclaration $callee_decl): ?IsOperation
	{
		$properties = ASTHelper::get_php_true_property_non_null_assertions($callee_decl);
		if (!$properties || !$expr->callee instanceof AccessingIdentifier) {
			return null;
		}

		$basing = $expr->callee->basing;
		foreach ($properties as $property_name) {
			$property = new AccessingIdentifier($basing, $property_name);
			$this->infer_accessing_identifier($property);
			return new IsOperation($property, TypeFactory::$_none, true);
		}

		return null;
	}

	private function get_php_predicate_type_assertion(BaseCallExpression $expr): ?IsOperation
	{
		if (!$this->is_weakly_checking || count($expr->arguments) !== 1 || $expr->named_arguments) {
			return null;
		}

		$callee_name = $this->get_predicate_callee_name($expr->callee);
		if ($callee_name === null || !str_starts_with($callee_name, 'is_')) {
			return null;
		}

		$callee_decl = ASTHelper::get_callee_declaration($expr);
		if (!$callee_decl instanceof IFunctionDeclaration) {
			return null;
		}

		$return_type = $callee_decl->get_expressed_type();
		if (!$return_type instanceof BoolType) {
			return null;
		}

		$parameter = $callee_decl->parameters[0] ?? null;
		if (!$parameter instanceof ParameterDeclaration) {
			return null;
		}

		$param_type = $parameter->get_expressed_type();
		if (!TypeHelper::is_nullable_type($param_type)) {
			return null;
		}

		$argument = $expr->arguments[0];
		if (!$argument instanceof Identifiable) {
			return null;
		}

		$argument_decl = ASTHelper::get_identifier_symbol($argument)->declaration ?? null;
		if (!$argument_decl instanceof IVariableDeclaration) {
			return null;
		}

		$argument_type = $this->get_assertion_original_type($argument_decl);
		if ($argument_type instanceof AnyType || !TypeHelper::is_nullable_type($argument_type)) {
			return null;
		}

		return new IsOperation($argument, TypeHelper::to_non_nullable($argument_type));
	}

	private function get_plain_callee_name(BaseExpression $callee): ?string
	{
		if (!$callee instanceof PlainIdentifier) {
			return null;
		}

		return ltrim($callee->name, '\\');
	}

	private function get_predicate_callee_name(BaseExpression $callee): ?string
	{
		if ($callee instanceof PlainIdentifier) {
			return ltrim($callee->name, '\\');
		}

		if ($callee instanceof AccessingIdentifier) {
			return $callee->name;
		}

		return null;
	}

	private function infer_base_if_block_with_assertion(BaseIfBlock $node, IsOperation $type_assertion, bool $is_not): ?BaseType
	{
		$assertion_left = $type_assertion->left;

		// cannot use type assertion when not an Identifiable
		if (!$assertion_left instanceof Identifiable) {
			// check block body
			$result_type = $this->infer_block($node);
			if ($node->else) {
				$result_type = $this->reduce_types_with_else_block($node, $result_type);
			}

			return $result_type;
		}

		$left_decl = ASTHelper::get_identifier_symbol($assertion_left)->declaration ?? null;
		if (!$left_decl instanceof IVariableDeclaration) {
			$result_type = $this->infer_block($node);
			if ($node->else) {
				$result_type = $this->reduce_types_with_else_block($node, $result_type);
			}

			return $result_type;
		}

		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = $this->get_assertion_original_type($left_decl);
		$left_type = $left_original_type ?? $this->get_unknown_php_value_type();
		$asserting_type = $type_assertion->right;
		if ($asserting_type instanceof InvalidType && $left_type instanceof InvalidableType) {
			$asserting_type = $left_type;
		}

		if ($is_not) {
			$asserted_then_type = $this->get_type_without_assertion($left_type, $asserting_type);
			$asserted_else_type = $this->get_asserted_branch_type($asserting_type);
		}
		else {
			$asserted_else_type = $this->get_type_without_assertion($left_type, $asserting_type);
			$asserted_then_type = $this->get_asserted_branch_type($asserting_type);
		}

		// it would infer with the asserted then type
		if ($asserted_then_type) {
			$left_decl->bind_type($asserted_then_type);
		}

		// check block body
		$result_type = $this->infer_block($node);

		// the rebound vars
		$main_rebound_vars = $node->get_rebound_variables();
		$main_rebound_types = $node->reset_rebound_types();

		if ($node->else) {
			// it would infer with the asserted else type
			$left_decl->bind_type($asserted_else_type ?? $left_original_type);
			$result_type = $this->reduce_types_with_else_block($node, $result_type);

			$this->deliver_bound_types_with_else(
				$node->else,
				$main_rebound_vars,
				$main_rebound_types,
				$left_decl,
				$asserted_then_type,
				$asserted_else_type
			);
		}
		else {
			$this->deliver_bound_types_without_else($main_rebound_vars, $main_rebound_types, $left_decl);
		}

		if ($assertion_left instanceof AccessingIdentifier
			&& !($assertion_left->basing instanceof PlainIdentifier && $assertion_left->basing->name === _THIS)) {
			// Keep narrowing for $this->prop, but still avoid carrying assumptions across arbitrary member chains.
			$left_decl->bind_type($left_original_type);
		}
		elseif ($this->can_deliver_assertion_after_transfer($node->condition)
			&& $this->block_exits_current_flow_for_narrowing($node)) {
			if ($is_not) {
				$left_type = $this->get_asserted_branch_type($asserting_type);
			}
			else {
				$left_type = $this->get_type_without_assertion($left_type, $asserting_type) ?? $left_type;
			}

			$left_decl->bind_type($left_type);
		}
		elseif (TypeHelper::get_bound_type($left_decl) === $asserted_then_type or $is_not) {
			// type not changed, so reset to original type
			$left_decl->bind_type($left_original_type);
		}

		return $result_type;
	}

	private function get_asserted_branch_type(BaseType $asserting_type): BaseType
	{
		return $asserting_type instanceof InvalidableType
			? TypeHelper::get_invalidable_sentinel_type($asserting_type)
			: $asserting_type;
	}

	private function get_type_without_assertion(BaseType $left_type, BaseType $asserting_type): ?BaseType
	{
		if ($asserting_type instanceof InvalidableType) {
			return TypeHelper::get_invalidable_valid_branch_type($asserting_type);
		}

		if ($left_type instanceof InvalidableType) {
			if ($asserting_type instanceof NoneType && $left_type->sentinel instanceof LiteralNone) {
				return TypeHelper::get_invalidable_valid_branch_type($left_type);
			}
		}

		if ($left_type instanceof UnionType) {
			return $left_type->deunite($asserting_type);
		}

		if ($asserting_type instanceof NoneType) {
			return TypeHelper::to_non_nullable($left_type);
		}

		return null;
	}

	private function get_assertion_original_type(IVariableDeclaration $decl): BaseType
	{
		if ($this->is_weakly_checking
			&& $decl instanceof IClassMemberDeclaration
			&& TypeHelper::get_raw_bound_type($decl) instanceof NoneType) {
			return ASTHelper::get_noted_type($decl) ?? $decl->declared_type ?? $decl->infered_type ?? $this->get_unknown_php_value_type();
		}

		return TypeHelper::get_bound_type($decl);
	}

	private function can_deliver_assertion_after_transfer(BaseExpression $condition): bool
	{
		if ($condition instanceof PrefixOperation && $condition->operator->is(OPID::BOOL_NOT)) {
			return $this->can_deliver_assertion_after_transfer($condition->expression);
		}

		return !($condition instanceof BinaryOperation
			&& ($condition->operator->is(OPID::BOOL_AND) || $condition->operator->is(OPID::BOOL_OR)));
	}

	private function block_exits_current_flow_for_narrowing(IBlock $block): bool
	{
		foreach ($block->body as $statement) {
			if ($statement instanceof ReturnStatement
				|| $statement instanceof ThrowStatement
				|| $statement instanceof ExitStatement
				|| $statement instanceof ContinueStatement) {
				return true;
			}
		}

		return false;
	}

	private function deliver_bound_types_without_else(array $main_rebound_vars, array $main_rebound_types, ?IVariableDeclaration $asserted_decl = null)
	{
		foreach ($main_rebound_vars as $idx => $var) {
			$new_type = $main_rebound_types[$idx];
			if ($var !== $asserted_decl) {
				$new_type = TypeHelper::get_bound_type($var)->unite($new_type);
			}

			$var->bind_type($new_type);
		}
	}

	private function deliver_bound_types_with_else(
		IElseBlock $else,
		array $main_rebound_vars,
		array $main_rebound_types,
		?IVariableDeclaration $asserted_decl = null,
		?BaseType $asserted_then_type = null,
		?BaseType $asserted_else_type = null
	)
	{
		$else_rebound_vars = $else->get_rebound_variables();
		$else_rebound_types = $else->reset_rebound_types();
		foreach ($main_rebound_vars as $idx => $var) {
			$idx_in_else = array_search($var, $else_rebound_vars, true);
			$basing_type = $idx_in_else === false
				? ($var !== $asserted_decl ? TypeHelper::get_bound_type($var) : $asserted_else_type)
				: $else_rebound_types[$idx_in_else];

			$new_type = $main_rebound_types[$idx];
			if ($basing_type) {
				$new_type = $basing_type->unite($new_type);
			}

			$var->bind_type($new_type);
		}

		foreach ($else_rebound_vars as $idx => $var) {
			if (array_search($var, $main_rebound_vars, true) !== false) {
				continue;
			}

			$new_type = $else_rebound_types[$idx];
			if ($var === $asserted_decl && $asserted_then_type !== null) {
				$new_type = $asserted_then_type->unite($new_type);
			}
			elseif ($var !== $asserted_decl) {
				$new_type = TypeHelper::get_bound_type($var)->unite($new_type);
			}

			$var->bind_type($new_type);
		}
	}

	protected function reduce_types_with_else_block(IElseAble $node, ?BaseType $previous_type): ?BaseType
	{
		$infered = $this->infer_else_block($node->else);
		return $previous_type
			? $this->reduce_types([$previous_type, $infered])
			: $infered;
	}

	protected function infer_else_block(IElseBlock $node): ?BaseType
	{
		if ($node instanceof ElseIfBlock) {
			$result_type = $this->infer_base_if_block($node);
		}
		else {
			$result_type = $this->infer_block($node);
		}

		return $result_type;
	}

	private function reduce_types_with_except_block(IExceptAble $node, ?BaseType $previous_type)
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
		if ($var !== null) {
			if ($var->declared_type === null) {
				$var->declared_type = TypeFactory::get_base_exception_type();
			}

			$this->check_variable_declaration($var);
		}

		$infered = $this->infer_block($node);

		return $infered;
	}

	private function infer_switch_block(SwitchBlock $node): ?BaseType
	{
		$subject_type = $this->infer_expression($node->subject);
		if (!$this->is_weakly_checking && !TypeHelper::is_case_testable_type($subject_type)) {
			$subject_type_name = self::get_type_name($subject_type);
			throw $this->new_syntax_error("The switch subject type should be String/Int/UInt/None, $subject_type_name given", $node->subject);
		}

		$infereds = [];
		$branch_rebounds = [];
		$switch_bindings = $this->snapshot_variable_bindings($this->block);
		foreach ($node->branches as $branch) {
			$this->restore_variable_bindings($switch_bindings);
			foreach ($branch->patterns as $pattern) {
				if ($pattern === null) {
					// the 'default' branch
					continue;
				}

					$case_type = $this->infer_expression($pattern);
					if (!$this->is_weakly_checking && !TypeHelper::is_switch_compatible($subject_type, $case_type)) {
						$subject_type_name = self::get_type_name($subject_type);
						$case_type_name = self::get_type_name($case_type);
						throw $this->new_syntax_error("Incompatible matching types, matching type is $subject_type_name, case type is $case_type_name", $pattern);
					}
			}

			$infereds[] = $this->infer_block($branch);
			foreach ($switch_bindings as $key => [$var, $original_type]) {
				$current_type = TypeHelper::get_raw_bound_type($var);
				if ($current_type === null) {
					continue;
				}

				if ($original_type !== null && TypeHelper::is_same_type($current_type, $original_type)) {
					continue;
				}

				$key = spl_object_id($var);
				if (!isset($branch_rebounds[$key])) {
					$branch_rebounds[$key] = [$var, $original_type, []];
				}
				$branch_rebounds[$key][2][] = $current_type;
			}
			$branch->reset_rebound_types();
		}
		$this->restore_variable_bindings($switch_bindings);

		$result_type = $infereds ? $this->reduce_types($infereds) : null;

		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		foreach ($branch_rebounds as [$var, $original_type, $types]) {
			$var->bind_type($this->reduce_types($original_type ? [$original_type, ...$types] : $types));
		}

		return $result_type;
	}

	private function snapshot_variable_bindings(IBlock $block): array
	{
		$bindings = [];
		foreach ($block->get_symbols() as $symbol) {
			$decl = $symbol->declaration ?? null;
			if ($decl instanceof IVariableDeclaration) {
				$bindings[spl_object_id($decl)] = [$decl, TypeHelper::get_raw_bound_type($decl)];
			}
		}

		return $bindings;
	}

	private function restore_variable_bindings(array $bindings): void
	{
		foreach ($bindings as [$decl, $type]) {
			TypeHelper::set_raw_bound_type($decl, $type);
		}
	}

	private function infer_match_block(MatchBlock $node): ?BaseType
	{
		$subject_type = $this->infer_expression($node->subject);
		if (!$this->is_weakly_checking && !TypeHelper::is_case_testable_type($subject_type)) {
			$subject_type_name = self::get_type_name($subject_type);
			throw $this->new_syntax_error("The match subject should be String/Int/UInt, $subject_type_name given", $node->subject);
		}

		$infereds = [];
		foreach ($node->arms as $arm) {
			foreach ($arm->patterns as $pattern) {
				if ($pattern instanceof DefaultPattern) {
					// the 'default' arm
					continue;
				}

					$case_type = $this->infer_expression($pattern);
					if (!$this->is_weakly_checking && !TypeHelper::is_switch_compatible($subject_type, $case_type)) {
						$subject_type_name = self::get_type_name($subject_type);
						$case_type_name = self::get_type_name($case_type);
						throw $this->new_syntax_error("Incompatible matching types, matching type is $subject_type_name, arm type is $case_type_name", $pattern);
					}
			}

			if ($arm->return instanceof ThrowExpression) {
				$this->infer_expression($arm->return);
			}
			else {
				$infereds[] = $this->infer_expression($arm->return);
			}
		}

		$result_type = $infereds ? $this->reduce_types($infereds) : null;
		return $result_type;
	}

	private function infer_for_block(ForBlock $node): ?BaseType
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

	private function infer_foreach_block(ForEachBlock $node): ?BaseType
	{
		$element_types = $this->expect_iter_element_types_for_expr($node->iterable);

		[$key_type, $val_type] = $element_types;
		$key = $node->key;
		$val = $node->val;
		if ($key instanceof PlainIdentifier) {
			ASTHelper::get_identifier_symbol($key)->declaration->infered_type = $key_type;
		}
		if ($val instanceof PlainIdentifier) {
			ASTHelper::get_identifier_symbol($val)->declaration->infered_type = $val_type;
		}

		$this->bind_loop_carried_rebound_types($node);

		$result_type = $this->infer_block($node);
		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	private function bind_loop_carried_rebound_types(IBlock $block): void
	{
		if (!$this->is_weakly_checking) {
			return;
		}

		$types = $this->collect_loop_carried_rebound_types($block);
		foreach ($types as [$decl, $type]) {
			$original_type = TypeHelper::get_bound_type($decl);
			$decl->bind_type($original_type->unite($type));
		}
	}

	private function collect_loop_carried_rebound_types(IBlock $block): array
	{
		$types = [];
		foreach ($block->body as $statement) {
			if (!$statement instanceof NormalStatement || !$statement->expression instanceof AssignmentOperation) {
				continue;
			}

			$left = $statement->expression->left;
			if (!$left instanceof PlainIdentifier) {
				continue;
			}

			$decl = ASTHelper::get_identifier_symbol($left)->declaration ?? null;
			if (!$decl instanceof IVariableDeclaration) {
				continue;
			}

			$type = $this->infer_loop_carried_assignment_type($statement->expression->right, $types);
			if ($type === null) {
				continue;
			}

			$key = spl_object_id($decl);
			if (isset($types[$key])) {
				$type = $types[$key][1]->unite($type);
			}

			$types[$key] = [$decl, $type];
		}

		return $types;
	}

	private function infer_loop_carried_assignment_type(BaseExpression $expr, array $types): ?BaseType
	{
		if ($expr instanceof PlainIdentifier) {
			$decl = ASTHelper::get_identifier_symbol($expr)->declaration ?? null;
			if (!$decl instanceof IVariableDeclaration) {
				return null;
			}

			$key = spl_object_id($decl);
			return $types[$key][1] ?? TypeHelper::get_bound_type($decl);
		}

		return $this->infer_expression($expr);
	}

	private function infer_forin_block(ForInBlock $node): ?BaseType
	{
		$element_types = $this->expect_iter_element_types_for_expr($node->iterable);

		$key = $node->key;
		$val = $node->val;
		$this->mark_checked($val);

		[$key_type, $val->infered_type] = $element_types;

		if ($key) {
			$key->infered_type = $key_type;
			$this->mark_checked($key);
		}

		$result_type = $this->infer_block($node);
		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	private function expect_iter_element_types_for_expr(BaseExpression $expr)
	{
		$iter_infered = $this->infer_expression($expr);
		$element_types = $this->infer_iter_element_types($iter_infered);
		if ($element_types === null) {
			$element_types = $this->infer_dynamic_foreach_element_types($expr, $iter_infered);
			if ($element_types === null) {
				$type_name = self::get_type_name($iter_infered);
				throw $this->new_syntax_error("Expected iterable type value, {$type_name} given", $expr);
			}
		}

		return $element_types;
	}

	private function infer_dynamic_foreach_element_types(BaseExpression $expr, BaseType $type): ?array
	{
		if (!$this->is_weakly_checking) {
			return null;
		}

		if ($type instanceof AnyType || $type instanceof MixedType || $this->is_php_foreachable_object_type($type)) {
			return [TypeFactory::$_dict_key, $this->get_unknown_php_value_type()];
		}

		if ($type instanceof InvalidableType && $type->sentinel instanceof LiteralNone) {
			return $this->infer_dynamic_foreach_element_types($expr, $type->valid_type);
		}

		if ($type instanceof UnionType) {
			return $this->infer_dynamic_foreach_element_types_for_union($expr, $type);
		}

		return null;
	}

	private function infer_dynamic_foreach_element_types_for_union(BaseExpression $expr, UnionType $type): ?array
	{
		$key_types = [];
		$val_types = [];
		foreach ($type->get_members() as $member_type) {
			if ($member_type instanceof NoneType) {
				continue;
			}

			$element_types = $this->infer_iter_element_types($member_type);
			if ($element_types === null && ($member_type instanceof AnyType || $member_type instanceof MixedType || $this->is_php_foreachable_object_type($member_type))) {
				$element_types = [TypeFactory::$_dict_key, $this->get_unknown_php_value_type()];
			}

			if ($element_types === null) {
				return null;
			}

			[$key_type, $val_type] = $element_types;
			$key_types[] = $key_type;
			$val_types[] = $val_type;
		}

		if (!$key_types) {
			return null;
		}

		return [TypeFactory::create_union_type($key_types), TypeFactory::create_union_type($val_types)];
	}

	private function is_php_foreachable_object_type(BaseType $type): bool
	{
		if ($type instanceof ObjectType) {
			return true;
		}

		$decl = TypeHelper::get_type_symbol($type)->declaration ?? null;
		return $type instanceof TypeReference && $decl instanceof ClassKindredDeclaration;
	}

	private function infer_iter_element_types(BaseType $expr_type)
	{
		$expr_type = TypeHelper::unwrap_excludable_type($expr_type);
		if ($expr_type instanceof IterableType) {
			// for Array or Dict
			$key_type = $expr_type instanceof ArrayType
				? TypeFactory::$_uint
				: TypeFactory::create_union_type([TypeFactory::$_uint, TypeFactory::$_string]);
			$val_type = $expr_type->generic_type ?? $this->get_unknown_php_value_type();
			$element_types = [$key_type, $val_type];
		}
		elseif ($expr_type instanceof TypeReference and $based_iter_ident = TypeFactory::find_iterator_identifier($expr_type)) {
			// for Iterator
			$key_type = $based_iter_ident->generic_types['K'] ?? $this->get_unknown_php_value_type();
			$val_type = $based_iter_ident->generic_types['V'] ?? $this->get_unknown_php_value_type();
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

	private function infer_forto_block(ForToBlock $node): ?BaseType
	{
		$start_type = $this->expect_infered_type($node->start, TypeFactory::$_int_types);
		$end_type = $this->expect_infered_type($node->end, TypeFactory::$_int_types);

		$key = $node->key;
		if ($key) {
			$key->infered_type = TypeFactory::$_uint;
			$this->mark_checked($key);
		}

		$val = $node->val;
		$this->mark_checked($val);

		// infer the val type
		$val->infered_type = ($start_type === TypeFactory::$_int || $end_type === TypeFactory::$_int)
			? TypeFactory::$_int
			: TypeFactory::$_uint;

		$result_type = $this->infer_block($node);
		if ($node->else) {
			$result_type = $this->reduce_types_with_else_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_while_block(WhileBlock $node): ?BaseType
	{
		$this->infer_expression($node->condition);
		$result_type = $this->infer_block($node);

		return $result_type;
	}

	private function infer_dowhile_block(DoWhileBlock $node): ?BaseType
	{
		$result_type = $this->infer_block($node);
		$this->infer_expression($node->condition);

		return $result_type;
	}

	private function infer_loop_block(LoopBlock $node): ?BaseType
	{
		$result_type = $this->infer_block($node);

		return $result_type;
	}

	private function infer_try_block(TryBlock $node): ?BaseType
	{
		$result_type = $this->infer_block($node);

		if ($node->has_exceptional()) {
			$result_type = $this->reduce_types_with_except_block($node, $result_type);
		}

		return $result_type;
	}

	private function infer_single_expression_block(IBlock $block): BaseType
	{
		// maybe block to check not a sub-block, so need a temp
		$temp_block = $this->block;
		$this->block = $block;

		$infered = $this->infer_expression($block->body);

		$this->block = $temp_block;

		return $infered;
	}

	private function infer_block(IBlock $block): ?BaseType
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

	private function collect_goto_labels(IBlock $block): array
	{
		$labels = [];
		$this->collect_goto_labels_from_block($block, $labels);
		return $labels;
	}

	private function collect_goto_labels_from_block(IBlock $block, array &$labels): void
	{
		if (isset($block->body) && is_array($block->body)) {
			foreach ($block->body as $statement) {
				if ($statement instanceof LabelStatement) {
					if (isset($labels[$statement->label])) {
						throw $this->new_syntax_error("Duplicated goto label '{$statement->label}'", $statement);
					}

					$labels[$statement->label] = $statement;
				}

				if ($statement instanceof IBlock) {
					$this->collect_goto_labels_from_block($statement, $labels);
				}
			}
		}

		if ($block instanceof BaseIfBlock || $block instanceof SwitchBlock) {
			$this->collect_goto_labels_from_else_branches($block, $labels);
		}

		if ($block instanceof IExceptAble) {
			foreach ($block->catchings as $catching) {
				$this->collect_goto_labels_from_block($catching, $labels);
			}

			if ($block->finally) {
				$this->collect_goto_labels_from_block($block->finally, $labels);
			}
		}

		if ($block instanceof SwitchBlock && isset($block->branches)) {
			foreach ($block->branches as $branch) {
				$this->collect_goto_labels_from_block($branch, $labels);
			}
		}
	}

	private function collect_goto_labels_from_else_branches(BaseIfBlock|SwitchBlock $block, array &$labels): void
	{
		$branch = $block->else;
		while ($branch instanceof IBlock) {
			$this->collect_goto_labels_from_block($branch, $labels);
			$branch = $branch instanceof ElseIfBlock ? $branch->else : null;
		}
	}

	private function is_control_transfering(IStatement $node)
	{
		return $node instanceof ExitStatement
			|| $node instanceof ReturnStatement
			|| $node instanceof ThrowStatement
		;
	}

	private function infer_statement(IStatement $node): ?BaseType
	{
		$infered = null;
		switch ($node::KIND) {
			case NormalStatement::KIND:
				$node->expression and $this->infer_expression($node->expression);
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
			case ReturnStatement::KIND:
				$infered = $this->infer_return_statement($node);
				break;
			case ExitStatement::KIND:
				$this->check_exit_statement($node);
			case BreakStatement::KIND:
			case ContinueStatement::KIND:
				break;
			case GotoStatement::KIND:
				$this->check_goto_statement($node);
				break;
			case LabelStatement::KIND:
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
			case DoWhileBlock::KIND:
				$infered = $this->infer_dowhile_block($node);
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

	private function check_goto_statement(GotoStatement $node): void
	{
		if (!isset($this->goto_labels[$node->target_label])) {
			throw $this->new_syntax_error("Goto target label '{$node->target_label}' not found", $node);
		}
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
		$hinted = $this->get_current_function_hinted_return_type();
		if ($hinted && $infered && $node->argument) {
			if ($this->function instanceof BaseDeclaration
				&& $this->warn_phpdoc_only_type_mismatch($this->function, $hinted, $infered, $node->argument, 'return')) {
				return $infered;
			}

			$this->assert_type_compatible($hinted, $infered, $node->argument, 'return');
		}

		return $infered;
	}

	private function get_current_function_hinted_return_type(): ?BaseType
	{
		if (!$this->function) {
			return null;
		}

		$hinted = $this->get_and_check_hinted_type($this->function);
		if ($hinted instanceof BaseType && $hinted->name === _TYPE_SELF) {
			$decl = $this->function->belong_block;
			$hinted = $decl->typing_identifier;
		}

		return $hinted;
	}

	private function check_exit_statement(ExitStatement $node)
	{
		$node->argument === null || $this->expect_infered_type($node->argument, TypeFactory::$_int_and_string_types);
	}

	private function check_unset_statement(UnsetStatement $node)
	{
		$argument = $node->argument;
		$allow_php_soft_key_access = $this->allow_php_soft_key_access;
		$this->allow_php_soft_key_access = true;
		try {
			$this->infer_expression($argument);
		}
		finally {
			$this->allow_php_soft_key_access = $allow_php_soft_key_access;
		}

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

	private function infer_interpolation(Interpolation $interpolation): BaseType
	{
		$infered = $this->infer_expression($interpolation->content);
		ASTHelper::set_expressed_type($interpolation, $infered);
		return $infered;
	}

	private function infer_expression(BaseExpression $node): BaseType
	{
		$expressed_type = ASTHelper::get_expressed_type($node);
		if ($expressed_type) {
			return $expressed_type;
		}

		switch ($node::KIND) {
			case PlainIdentifier::KIND:
				$infered = $this->infer_plain_identifier($node);
				break;
			case LiteralNone::KIND:
				$infered = TypeFactory::$_none;
				break;
			case LiteralDefaultMark::KIND:
				$infered = TypeFactory::$_default_marker;
				break;
			case PlainLiteralString::KIND:
				$infered = TeaHelper::is_pure_string($node->value)
					? TypeFactory::$_plain
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
			case FirstClassCallableExpression::KIND:
				$infered = $this->infer_first_class_callable_expression($node);
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
			case CastOperation::KIND:
				$infered = $this->infer_cast_operation($node);
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
			case ThrowExpression::KIND:
				$infered = $this->infer_throw_expression($node);
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

			case MatchBlock::KIND:
				$infered = $this->infer_match_block($node);
				break;
			case DefaultPattern::KIND:
				$infered = TypeFactory::$_any;
				break;
			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unknow expression kind: '{$kind}'", $node);
		}

		ASTHelper::set_expressed_type($node, $infered);
		return $infered;
	}

	private function get_unknown_php_value_type(): BaseType
	{
		return TypeFactory::$_mixed;
	}

	private function infer_square_accessing(SquareAccessing $node): BaseType
	{
		$basing_type = $this->infer_expression($node->basing);
		if ($basing_type instanceof InvalidableType
			&& $basing_type->sentinel instanceof LiteralNone
			&& ($this->is_weakly_checking || $this->allow_none_coalescing_key_access)) {
			$basing_type = $basing_type->valid_type;
		}
		$basing_type = TypeHelper::unwrap_excludable_type($basing_type);

		$infered = null;
		if ($basing_type instanceof ArrayType) {
			$infered = $basing_type->generic_type ?? $this->get_unknown_php_value_type();
		}
		elseif ($this->is_php_dynamic_top_type($basing_type)) {
			$infered = $this->get_unknown_php_value_type();
		}
		elseif ($this->is_weakly_checking && $basing_type instanceof NoneType) {
			$infered = $this->get_unknown_php_value_type();
		}
		elseif ($basing_type instanceof UnionType) {
			if ($basing_type->is_all_array_types()) {
				$member_value_types = [];
				foreach ($basing_type->get_members() as $member) {
					$member_value_types[] = $member->generic_type;
				}

				$infered = $this->reduce_types($member_value_types);
			}
			elseif ($this->allow_weak_square_access_for_union_type($basing_type)) {
				$infered = $this->get_unknown_php_value_type();
			}
		}

		if ($infered === null) {
			if ($this->allow_weak_square_access_for_phpdoc_dict($node, $basing_type)) {
				return $this->get_unknown_php_value_type();
			}

			if ($this->allow_weak_square_access_for_phpdoc_virtual_type($node, $basing_type)) {
				return $this->get_unknown_php_value_type();
			}

			$type_name = $this->get_type_name($basing_type);
			throw $this->new_syntax_error("Cannot use square accessing for type {$type_name}", $node);
		}

		return $infered;
	}

	private function infer_key_accessing(KeyAccessing $node): BaseType
	{
		$key_expr = $node->key;
		$basing_type = $this->infer_expression($node->basing);
		if ($basing_type instanceof InvalidableType
			&& $basing_type->sentinel instanceof LiteralNone
			&& ($this->is_weakly_checking || $this->allow_none_coalescing_key_access)) {
			$basing_type = $basing_type->valid_type;
		}
		$basing_type = TypeHelper::unwrap_excludable_type($basing_type);
		$key_type = $key_expr ? $this->infer_expression($key_expr) : null;
		if ($key_type instanceof BaseType) {
			$key_type = TypeHelper::unwrap_excludable_type($key_type);
		}

		if ($basing_type instanceof ArrayType) {
			if ($key_expr && $key_type instanceof ObjectType) {
				$type_name = self::get_type_name($key_type);
				throw $this->new_syntax_error("Index type for Array accessing should be UInt, {$type_name} given", $key_expr);
			}

			if ($key_expr and $key_type !== TypeFactory::$_uint and !$this->is_weakly_checking) {
				$type_name = self::get_type_name($key_type);
				throw $this->new_syntax_error("Index type for Array accessing should be UInt, {$type_name} given", $key_expr);
			}

			$infered = $basing_type->generic_type ?? $this->get_unknown_php_value_type();
		}
		elseif ($basing_type instanceof DictType) {
			if ($key_expr === null) {
				throw $this->new_syntax_error("Invalid accessing for Dict", $node);
			}

			$this->assert_dict_key_type($key_type, $key_expr);
			$known_member_type = $this->get_known_dict_member_type($basing_type, $key_expr);
			if ($known_member_type === null && $this->allow_weak_dynamic_key_access_for_phpdoc_generic_array($node, $basing_type)) {
				return $this->get_unknown_php_value_type();
			}

			if ($known_member_type === null && $this->allow_weak_dynamic_dict_literal_member_access($node, $basing_type)) {
				return $this->get_unknown_php_value_type();
			}

			$infered = $known_member_type ?? $basing_type->generic_type ?? $this->get_unknown_php_value_type();
		}
		elseif ($this->is_array_access_type($basing_type)) {
			// if non key, that's Array access, else just allow Dict as the actual type
			$key_expr and $this->assert_dict_key_type($key_type, $key_expr);
			$infered = $this->get_unknown_php_value_type();
		}
		elseif ($basing_type instanceof StringType) {
			if ($key_type !== TypeFactory::$_uint && $key_type !== TypeFactory::$_int) {
				if ($this->is_weakly_checking) {
					$infered = $this->get_unknown_php_value_type();
					return $infered;
				}

				throw $this->new_syntax_error("Index type for String should be Int/UInt, '{$key_type->name}' given", $node);
			}

			$infered = TypeFactory::$_string;
		}
		elseif ($basing_type instanceof UnionType) {
			if ($basing_type->is_all_array_types()) {
				if ($key_type !== TypeFactory::$_uint && !($this->is_weakly_checking && $key_type instanceof AnyType)) {
					$type_name = self::get_type_name($key_type);
					throw $this->new_syntax_error("Index type for Array accessing should be UInt, {$type_name} given", $key_expr);
				}
			}
			elseif ($basing_type->is_all_dict_types()) {
				$this->assert_dict_key_type($key_type, $key_expr);
			}
			elseif ($this->is_generalized_php_array_union($basing_type)) {
				$this->assert_generalized_php_array_key_type($key_type, $key_expr);
			}
			elseif ($this->allow_none_coalescing_key_access && $basing_type->has_array_or_dict_type()) {
				// Allow nullable array/dict access on the left side of ?? and let coalescing handle none.
			}
			elseif ($this->is_weakly_checking && $this->union_has_key_accessible_type($basing_type)) {
				// pass
			}
			elseif ($this->is_string_key_accessible_union($basing_type)) {
				$infered = TypeFactory::$_string;
				return $infered;
			}
			elseif ($this->allow_weak_dynamic_key_access($basing_type)) {
				$infered = $this->get_unknown_php_value_type();
				return $infered;
			}
			else {
				$type_name = $this->get_type_name($basing_type);
				throw $this->new_syntax_error("Cannot use key accessing to type {$type_name}", $node);
			}

			if ($this->allow_weak_dynamic_key_access_for_phpdoc_generic_array($node, $basing_type)) {
				return $this->get_unknown_php_value_type();
			}

			$member_value_types = [];
			foreach ($basing_type->get_members() as $member) {
				$member_value_types[] = $member->generic_type ?? $this->get_unknown_php_value_type();
			}

			$infered = $this->reduce_types($member_value_types);
		}
		elseif ($this->allow_none_coalescing_key_access && $basing_type instanceof NoneType) {
			$infered = TypeFactory::$_none;
		}
		elseif ($this->is_weakly_checking && $this->allow_php_soft_key_access && $basing_type instanceof NoneType) {
			$infered = $this->get_unknown_php_value_type();
		}
		else {
			if ($this->allow_weak_dynamic_none_key_access($node, $basing_type)) {
				return $this->get_unknown_php_value_type();
			}

			if ($this->allow_weak_dynamic_key_access($basing_type)) {
				return $this->get_unknown_php_value_type();
			}
			$type_name = $this->get_type_name($basing_type);
			throw $this->new_syntax_error("Cannot use key accessing to type {$type_name}", $node);
		}

		return $infered;
	}

	private function allow_weak_square_access_for_union_type(UnionType $type): bool
	{
		if (!$this->is_weakly_checking) {
			return false;
		}

		if ($this->union_has_key_accessible_type($type)) {
			return true;
		}

		foreach ($type->get_members() as $member) {
			if ($member instanceof AnyType) {
				return true;
			}
		}

		return false;
	}

	private function allow_weak_square_access_for_phpdoc_dict(SquareAccessing $node, BaseType $type): bool
	{
		if (!$this->is_weakly_checking || !$type instanceof DictType) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return false;
		}

		$this->add_syntax_warning("PHPDoc declares Dict but value is used as list; degrading to dynamic access in PHP weak mode", $node);
		return true;
	}

	private function allow_weak_square_access_for_phpdoc_virtual_type(SquareAccessing $node, BaseType $type): bool
	{
		if (!$this->is_weakly_checking || !$type instanceof TypeReference) {
			return false;
		}

		$type_decl = TypeHelper::get_type_symbol($type)->declaration ?? null;
		if (!$type_decl instanceof ClassKindredDeclaration || !$type_decl->is_virtual) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return false;
		}

		$this->add_syntax_warning("PHPDoc generic type is unresolved and value is used as list; degrading to dynamic access in PHP weak mode", $node);
		return true;
	}

	private function allow_weak_dynamic_key_access(BaseType $type): bool
	{
		if (!$this->is_weakly_checking) {
			return false;
		}

		if ($this->is_php_dynamic_top_type($type)) {
			return true;
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if ($this->is_php_dynamic_top_type($member)) {
					return true;
				}
			}
		}

		return false;
	}

	private function union_has_key_accessible_type(UnionType $type): bool
	{
		foreach ($type->get_members() as $member) {
			if ($member instanceof ArrayType || $member instanceof DictType || $this->is_array_access_type($member)) {
				return true;
			}
		}

		return false;
	}

	private function is_string_key_accessible_union(UnionType $type): bool
	{
		foreach ($type->get_members() as $member) {
			if (!$member instanceof StringType) {
				return false;
			}
		}

		return true;
	}

	private function is_generalized_php_array_union(UnionType $type): bool
	{
		$has_array = false;
		$has_dict = false;
		foreach ($type->get_members() as $member) {
			if ($member instanceof ArrayType) {
				$has_array = true;
			}
			elseif ($member instanceof DictType) {
				$has_dict = true;
			}
			else {
				return false;
			}
		}

		return $has_array && $has_dict;
	}

	private function allow_weak_dynamic_key_access_for_phpdoc_generic_array(KeyAccessing $node, BaseType $type): bool
	{
		if (!$this->is_weakly_checking || $node->key === null || !$this->is_unknown_phpdoc_generic_array_key_access_type($type, $node->key)) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return false;
		}

		return true;
	}

	private function allow_weak_dynamic_dict_literal_member_access(KeyAccessing $node, DictType $type): bool
	{
		if (!$this->is_weakly_checking || $node->key === null || $this->get_literal_dict_key($node->key) === null) {
			return false;
		}

		if ($type->generic_type === null || $type->generic_type instanceof AnyType) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || $decl->declared_type !== null || $decl->noted_type !== null) {
			return false;
		}

		return true;
	}

	private function allow_weak_dynamic_none_key_access(KeyAccessing $node, BaseType $type): bool
	{
		if (!$this->is_weakly_checking || !$type instanceof NoneType) {
			return false;
		}

		$basing = $node->get_final_basing();
		if (!$basing instanceof Identifiable) {
			return false;
		}

		$decl = ASTHelper::get_identifier_symbol($basing)->declaration ?? null;
		if (!$decl instanceof BaseDeclaration || $decl->declared_type !== null || $decl->noted_type !== null) {
			return false;
		}

		$this->add_syntax_warning("PHP weak dynamic value may be null during key access; degrading to dynamic access", $node);
		return true;
	}

	private function is_unknown_phpdoc_generic_array_key_access_type(BaseType $type, BaseExpression $key_expr): bool
	{
		if ($type instanceof DictType) {
			return $type->generic_type !== null && $this->get_known_dict_member_type($type, $key_expr) === null;
		}

		return $type instanceof UnionType
			&& $this->is_generalized_php_array_union($type)
			&& $this->get_known_union_dict_member_type($type, $key_expr) === null;
	}

	private function assert_generalized_php_array_key_type(BaseType $key_type, BaseExpression $key_expr): void
	{
		if (TypeHelper::is_dict_key_type($key_type) || $key_type === TypeFactory::$_uint || $key_type instanceof AnyType || $key_type instanceof MixedType) {
			return;
		}

		if ($this->allow_weak_generalized_php_array_key_type($key_type, $key_expr)) {
			return;
		}

		$type_name = self::get_type_name($key_type);
		throw $this->new_syntax_error("Key type for Array|Dict accessing should be String/Int/UInt, {$type_name} given", $key_expr);
	}

	private function allow_weak_generalized_php_array_key_type(BaseType $key_type, BaseExpression $key_expr): bool
	{
		if (!$this->is_weakly_checking) {
			return false;
		}

		if (TypeHelper::is_dict_key_type($key_type) || $key_type === TypeFactory::$_uint || $key_type instanceof AnyType || $key_type instanceof MixedType) {
			return true;
		}

		if ($key_type instanceof UnionType) {
			foreach ($key_type->get_members() as $member) {
				if (!$this->allow_weak_generalized_php_array_key_type($member, $key_expr)) {
					return false;
				}
			}
			return true;
		}

		if ($key_type instanceof InvalidableType
			&& $this->allow_weak_generalized_php_array_key_type($key_type->valid_type, $key_expr)) {
			$this->add_syntax_warning("PHP weak array access uses Invalidable as key", $key_expr);
			return true;
		}

		if ($key_type instanceof FloatType) {
			$this->add_syntax_warning("PHP weak array access converts Float key to Int", $key_expr);
			return true;
		}

		return false;
	}

	private function is_array_access_type(BaseType $type)
	{
		$decl = null;
		if ($type instanceof TypeReference) {
			$symbol = TypeHelper::get_type_symbol($type);
			if ($symbol === null) {
				$symbol = $this->find_type_symbol_and_check_declaration($type);
				TypeHelper::set_type_symbol($type, $symbol);
			}

			$decl = $symbol ? $this->resolve_use_symbol_declaration($symbol, $type) : null;
			if ($decl instanceof ClassKindredDeclaration && !$this->is_classkindred_ready($decl)) {
				$this->preprocess_classkindred_declaration($decl);
			}
		}

		return $type instanceof AnyType
			|| $type instanceof MixedType
			|| ($decl instanceof ClassKindredDeclaration && $decl->has_feature(ClassFeature::ARRAY_ACCESS));
	}

	private function assert_dict_key_type(BaseType $key_type, BaseExpression $key_expr)
	{
		if ($key_type instanceof AnyType || $key_type instanceof MixedType) {
			return;
		}

		if (!TypeHelper::is_dict_key_type($key_type) && !$this->is_weakly_checking) {
			throw $this->new_syntax_error("Key type for Dict accessing should be String/Int, '{$key_type->name}' given", $key_expr);
		}
	}

	private function get_known_dict_member_type(DictType $type, BaseExpression $key_expr): ?BaseType
	{
		$key = $this->get_literal_dict_key($key_expr);

		return $key !== null ? ($type->known_member_types[$key] ?? null) : null;
	}

	private function get_known_union_dict_member_type(UnionType $type, ?BaseExpression $key_expr): ?BaseType
	{
		if ($key_expr === null) {
			return null;
		}

		$member_types = [];
		foreach ($type->get_members() as $member) {
			if (!$member instanceof DictType) {
				continue;
			}

			$member_type = $this->get_known_dict_member_type($member, $key_expr);
			if ($member_type !== null) {
				$member_types[] = $member_type;
			}
		}

		return $member_types ? $this->reduce_types($member_types) : null;
	}

	private function infer_assignment_operation(AssignmentOperation $node)
	{
		$left = $node->left;
		$right = $node->right;
		$infered = $this->infer_expression($right);
		$left_decl = null;
		$rebind_decl = null;

		if ($infered === TypeFactory::$_void) {
			if ($this->is_weakly_checking) {
				$infered = $this->get_unknown_php_value_type();
			}
			else {
				throw $this->new_syntax_error("The returns type is Void, cannot use as value", $right);
			}
		}

		if ($left instanceof AccessingIdentifier) {
			$this->infer_accessing_identifier($left);
			$left_decl = ASTHelper::get_identifier_symbol($left)->declaration ?? null;
			$left_type = $left_decl ? $this->get_assignment_target_type($left_decl) : $this->get_unknown_php_value_type();
			if ($this->is_weakly_checking
				&& $left_decl instanceof IVariableDeclaration
				&& $this->is_this_member_accessing($left)) {
				$rebind_decl = $left_decl;
			}
		}
		elseif ($left instanceof KeyAccessing) {
			$left_type = $this->infer_key_accessing($left); // it should be not null
			$left_type = $this->get_key_accessing_assignment_type($left, $left_type);
		}
		elseif ($left instanceof SquareAccessing) {
			$left_type = $this->infer_square_accessing($left); // it should be not null
			if ($this->is_weakly_checking) {
				$left_type = $this->widen_php_array_append_assignment($left, $left_type, $infered);
			}
		}
		elseif ($left instanceof PlainIdentifier) {
			$left_decl = ASTHelper::get_identifier_symbol($left)->declaration ?? null;
			if ($this->is_weakly_checking && $left instanceof VariableIdentifier && !$left_decl instanceof IVariableDeclaration) {
				$left_decl = $this->create_php_dynamic_variable_declaration($left);
			}
			$left_type = $left_decl->get_hinted_type();
			if ($this->is_weakly_checking && $this->is_catch_variable_declaration($left_decl)) {
				$left_type = TypeFactory::$_any;
			}
			$this->rebind_type_for_variable($left_decl, $infered, $right);
		}
		elseif ($left instanceof Destructuring) {
			$left_type = $this->infer_destructuring($left);
		}
		elseif ($left instanceof BinaryOperation && $left->operator === OperatorFactory::$member_accessing) {
			$left_type = $this->get_unknown_php_value_type();
		}
		else {
			throw $this->new_syntax_error("Required assignable expression", $left);
		}

		if (!ASTHelper::is_assignable_expr($left)) {
			if ($left instanceof KeyAccessing) {
				throw $this->new_syntax_error("Cannot change a immutable item", $left->basing);
			}
			elseif ($left instanceof SquareAccessing) {
				throw $this->new_syntax_error("Cannot change a immutable item", $left);
			}
			else {
				throw $this->new_syntax_error("Cannot assign to a final(un-reassignable) item", $left);
			}
		}

		if ($left_decl instanceof BaseDeclaration
			&& ($phpdoc_type = $this->get_phpdoc_only_noted_type($left_decl)) instanceof BaseType
			&& $this->warn_phpdoc_only_type_mismatch($left_decl, $phpdoc_type, $infered, $right, 'assign')) {
			$left_type = $this->get_unknown_php_value_type();
		}

		$this->assert_type_compatible($left_type, $infered, $right);
		if ($rebind_decl) {
			$this->rebind_type_for_variable($rebind_decl, $infered, $right);
		}
		// if ($left_type) {
		// 	$this->assert_type_compatible($left_type, $infered, $right);
		// }
		// else: undeclared variable fallback was removed when PHP weak dynamic
		// variable declarations became explicit.

		return $infered;
	}

	private function is_catch_variable_declaration($decl): bool
	{
		return $decl instanceof VariableDeclaration && $decl->block instanceof CatchBlock;
	}

	private function is_this_member_accessing(AccessingIdentifier $node): bool
	{
		return $node->basing instanceof PlainIdentifier && $node->basing->name === _THIS;
	}

	private function get_assignment_target_type(BaseDeclaration $decl): BaseType
	{
		if ($this->is_weakly_checking && $decl->declared_type === null && ASTHelper::get_noted_type($decl) !== null) {
			return $this->get_unknown_php_value_type();
		}

		return $decl->get_hinted_type();
	}

	private function get_phpdoc_only_noted_type(?BaseDeclaration $decl): ?BaseType
	{
		if (!$decl instanceof BaseDeclaration
			|| $decl->declared_type !== null
			|| !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return null;
		}

		return ASTHelper::get_noted_type($decl);
	}

	private function get_key_accessing_assignment_type(KeyAccessing $left, BaseType $read_type): BaseType
	{
		$basing_type = ASTHelper::get_expressed_type($left->basing);
		if ($basing_type instanceof DictType && $basing_type->generic_type instanceof BaseType) {
			return $basing_type->generic_type;
		}

		return $read_type;
	}

	private function widen_php_array_append_assignment(SquareAccessing $left, BaseType $read_type, BaseType $assigned_type): BaseType
	{
		if (!$left->basing instanceof Identifiable) {
			return $read_type;
		}

		$decl = ASTHelper::get_identifier_symbol($left->basing)->declaration ?? null;
		if (!$decl instanceof IVariableDeclaration) {
			return $read_type;
		}

		$basing_type = ASTHelper::get_expressed_type($left->basing);
		if (!$basing_type instanceof ArrayType) {
			return $read_type;
		}

		$current_generic = $basing_type->generic_type ?? $this->get_unknown_php_value_type();
		if (TypeHelper::is_value_compatible($current_generic, $assigned_type)) {
			return $read_type;
		}

		$widened_generic = $current_generic->unite($assigned_type);
		$decl->bind_type(TypeFactory::create_array_type($widened_generic));
		$this->block->add_rebound_variable($decl);

		return $widened_generic;
	}

	private function create_php_dynamic_variable_declaration(VariableIdentifier $identifier): VariableDeclaration
	{
		$decl = new VariableDeclaration($identifier->name);
		$decl->block = $this->block;
		$symbol = new Symbol($decl);
		ASTHelper::set_identifier_symbol($identifier, $symbol);
		if ($this->block instanceof IBlock) {
			$this->block->set_symbol($identifier->name, $symbol);
		}

		return $decl;
	}

	private function rebind_type_for_variable(IVariableDeclaration $decl, BaseType $type, ?BaseExpression $value = null)
	{
		$decl->bind_type($type);
		TypeHelper::set_bound_value($decl, $value);
		$this->block->add_rebound_variable($decl);
	}

	private function infer_destructuring(Destructuring $expr)
	{
		foreach ($expr->items as $item) {
			if ($item === null) {
				continue;
			}

			if ($item instanceof DictMember) {
				$this->infer_dict_member($item);
			}
			else {
				$this->infer_expression($item);
			}
		}

		return TypeFactory::$_array;
	}

	private function infer_binary_operation(BinaryOperation $node): BaseType
	{
		$operator = $node->operator;
		$is_none_coalescing = $operator->is(OPID::NONE_COALESCING);

		$left_expr = $node->left;
		if ($is_none_coalescing and $left_expr instanceof AccessingIdentifier) {
			$left_type = $this->infer_accessing_identifier($left_expr, true);
		}
		else {
			$left_type = $this->infer_expression($left_expr);
		}

		$right_expr = $node->right;
		$asserted_decl = null;
		$asserted_original_type = null;
		if ($operator->is(OPID::BOOL_AND)) {
			$bindings = [];
			$this->apply_condition_assertions($left_expr, true, $bindings);
		}

		$right_type = $this->infer_expression($right_expr);

		if (isset($bindings)) {
			$this->restore_condition_assertions($bindings);
		}

		if ($this->is_weakly_checking
			&& $operator->is(OPID::ADDITION)
			&& ($array_union_type = TypeHelper::infer_php_array_union_operation($left_type, $right_type))) {
			$infered = $array_union_type;
		}
		elseif (OperatorFactory::is_number_operator($operator)) {
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
			$this->assign_null_comparison_type_assertion($node, $left_expr, $left_type, $right_expr, $right_type);
			$this->assign_false_literal_comparison_type_assertion($node, $left_expr, $left_type, $right_expr, $right_type);
			$this->assign_invalidable_sentinel_comparison_type_assertion($node, $left_expr, $left_type, $right_expr, $right_type);
			$this->assign_false_sentinel_comparison_type_assertion($node, $left_expr, $left_type, $right_expr, $right_type);

			// assign type assertion for and-operation
			// uses for assert type in if-block/conditional-expression
			if ($operator->is(OPID::BOOL_AND)) {
				if ($left_expr instanceof BinaryOperation and $assertion = $this->get_type_assertion_for($left_expr)) {
					$this->set_type_assertion_for($node, $assertion);
				}
				elseif ($right_expr instanceof BinaryOperation and $assertion = $this->get_type_assertion_for($right_expr)) {
					$this->set_type_assertion_for($node, $assertion);
				}
			}
		}
		elseif ($operator->is(OPID::CONCAT)) {
			// String or Array
			if ($this->is_weakly_checking) {
				if ($left_type instanceof ArrayType) {
					if ($right_type instanceof AnyType) {
						$node->operator = OperatorFactory::$array_concat; // replace to array concat
						$infered = $left_type;
					}
					elseif (!$right_type instanceof ArrayType) {
						$type_name = $this->get_type_name($left_type);
						throw $this->new_syntax_error("The concat operation cannot use for '$type_name' type targets", $left_expr);
					}
					else {
						$this->assert_type_compatible($left_type, $right_type, $right_expr, 'concat');
						$node->operator = OperatorFactory::$array_concat; // replace to array concat
						$infered = $left_type;
					}
				}
				else {
					$this->assert_php_concat_operable($left_type, $left_expr);
					$this->assert_php_concat_operable($right_type, $right_expr);
					$infered = TypeFactory::$_string;
				}
			}
			elseif ($left_type instanceof ArrayType) {
				$this->assert_type_compatible($left_type, $right_type, $right_expr, 'concat');
				$node->operator = OperatorFactory::$array_concat; // replace to array concat
				$infered = $left_type;
			}
			elseif (!TypeHelper::is_string_concatable_type($left_type)) {
				$type_name = $this->get_type_name($left_type);
				throw $this->new_syntax_error("The concat operation cannot use for '$type_name' type targets", $left_expr);
			}
			else {
				// string
				$is_pure = $left_type instanceof IPureType && $right_type instanceof IPureType;
				$infered = $is_pure ? TypeFactory::$_plain : TypeFactory::$_string;
			}
		}
		elseif ($operator->is(OPID::REPEAT)) {
			if (!TypeHelper::is_string_concatable_type($left_type)) {
				$type_name = $this->get_type_name($left_type);
				throw $this->new_syntax_error("Expected Stringable, {$type_name} given", $left_expr);
			}
			elseif (!$right_type instanceof UIntType) {
				$type_name = $this->get_type_name($right_type);
				throw $this->new_syntax_error("Expected UInt, {$type_name} given", $right_expr);
			}

			// string
			$is_pure = $left_type instanceof IPureType;
			$infered = $is_pure ? TypeFactory::$_plain : TypeFactory::$_string;
		}
		// elseif ($operator->is(OPID::MERGE)) {
		// 	// Array or Dict
		// 	if (!$left_type instanceof DictType) {
		// 		throw $this->new_syntax_error("'merge' operation just support Dict type targets", $node);
		// 	}

		// 	$this->assert_type_compatible($left_type, $right_type, $right_expr, 'merge');
		// 	$infered = $left_type;
		// }
		elseif ($is_none_coalescing) {
			$infered = $this->infer_none_coalesced_type($left_type, $right_type);
		}
		elseif (OperatorFactory::is_bitwise_operator($operator)) {
			$infered = $this->reduce_types([$left_type, $right_type]);
		}
		elseif ($operator->is(OPID::MEMBER_ACCESSING)) {
			$infered = $this->get_unknown_php_value_type();
		}
		else {
			$sign = $node->operator->get_debug_sign();
			throw $this->new_syntax_error("Unknow binary operator: {$sign}", $node);
		}

		return $infered;
	}

	private function assert_php_concat_operable(BaseType $type, BaseExpression $expr): void
	{
		$type = TypeHelper::unwrap_excludable_type($type);

		if ($this->is_php_dynamic_top_type($type)) {
			return;
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				$this->assert_php_concat_operable($member, $expr);
			}
			return;
		}

		if ($type instanceof InvalidableType) {
			if (TypeHelper::is_string_concatable_type($type->valid_type) || $this->is_php_stringable_object_type($type->valid_type)) {
				if ($type->sentinel instanceof LiteralNone) {
					return;
				}
				$this->add_syntax_warning("PHP weak concat converts Invalidable to String", $expr);
				return;
			}
		}

		if (TypeHelper::is_string_concatable_type($type) || $this->is_php_stringable_object_type($type)) {
			return;
		}

		if ($type instanceof MetaType) {
			$this->add_syntax_warning("PHP weak concat converts MetaType to class string", $expr);
			return;
		}

		if ($type instanceof BoolType) {
			$this->add_syntax_warning("PHP weak concat converts Bool to String", $expr);
			return;
		}

		$type_name = $this->get_type_name($type);
		throw $this->new_syntax_error("The concat operation cannot use for '$type_name' type targets", $expr);
	}

	private function is_php_stringable_object_type(BaseType $type): bool
	{
		$decl = $type instanceof TypeReference ? (TypeHelper::get_type_symbol($type)->declaration ?? null) : null;
		if (!$decl instanceof ClassKindredDeclaration) {
			return false;
		}

		return $this->find_member_symbol_in_class_declaration($decl, '__toString') !== null;
	}

	private function assign_null_comparison_type_assertion(
		BinaryOperation $node,
		BaseExpression $left_expr,
		BaseType $left_type,
		BaseExpression $right_expr,
		BaseType $right_type
	): void {
		$operator = $node->operator;
		if (!$operator->is(OPID::EQUAL)
			&& !$operator->is(OPID::IDENTICAL)
			&& !$operator->is(OPID::NOT_EQUAL)
			&& !$operator->is(OPID::NOT_IDENTICAL)) {
			return;
		}

		$is_not = $operator->is(OPID::NOT_EQUAL) || $operator->is(OPID::NOT_IDENTICAL);
		if ($right_type instanceof NoneType && $left_expr instanceof Identifiable) {
			$this->set_type_assertion_for($node, new IsOperation($left_expr, TypeFactory::$_none, $is_not));
		}
		elseif ($left_type instanceof NoneType && $right_expr instanceof Identifiable) {
			$this->set_type_assertion_for($node, new IsOperation($right_expr, TypeFactory::$_none, $is_not));
		}
	}

	private function assign_false_sentinel_comparison_type_assertion(
		BinaryOperation $node,
		BaseExpression $left_expr,
		BaseType $left_type,
		BaseExpression $right_expr,
		BaseType $right_type
	): void {
		$operator = $node->operator;
		if ($operator->is(OPID::GREATERTHAN)) {
			$this->assign_false_sentinel_positive_comparison_assertion($node, $left_expr, $left_type, $right_type);
		}
		elseif ($operator->is(OPID::LESSTHAN)) {
			$this->assign_false_sentinel_positive_comparison_assertion($node, $right_expr, $right_type, $left_type);
		}
	}

	private function assign_false_literal_comparison_type_assertion(
		BinaryOperation $node,
		BaseExpression $left_expr,
		BaseType $left_type,
		BaseExpression $right_expr,
		BaseType $right_type
	): void {
		$operator = $node->operator;
		if (!$operator->is(OPID::EQUAL)
			&& !$operator->is(OPID::IDENTICAL)
			&& !$operator->is(OPID::NOT_EQUAL)
			&& !$operator->is(OPID::NOT_IDENTICAL)) {
			return;
		}

		$is_not = $operator->is(OPID::NOT_EQUAL) || $operator->is(OPID::NOT_IDENTICAL);
		if ($this->is_literal_false($right_expr) && $left_expr instanceof Identifiable) {
			if ($this->is_false_sentinel_invalidable($left_type)) {
				$this->set_type_assertion_for($node, new IsOperation($left_expr, $left_type, $is_not));
			}
			elseif ($this->is_bool_sentinel_union($left_type)) {
				$this->set_type_assertion_for($node, new IsOperation($left_expr, TypeFactory::$_bool, $is_not));
			}
		}
		elseif ($this->is_literal_false($left_expr) && $right_expr instanceof Identifiable) {
			if ($this->is_false_sentinel_invalidable($right_type)) {
				$this->set_type_assertion_for($node, new IsOperation($right_expr, $right_type, $is_not));
			}
			elseif ($this->is_bool_sentinel_union($right_type)) {
				$this->set_type_assertion_for($node, new IsOperation($right_expr, TypeFactory::$_bool, $is_not));
			}
		}
	}

	private function is_literal_false(BaseExpression $expr): bool
	{
		return $expr instanceof LiteralBoolean
			&& ($expr->value === false || $expr->value === '' || $expr->value === '0');
	}

	private function is_false_sentinel_invalidable(BaseType $type): bool
	{
		return $type instanceof InvalidableType
			&& TypeHelper::is_invalidable_sentinel($type, new LiteralBoolean(false));
	}

	private function assign_invalidable_sentinel_comparison_type_assertion(
		BinaryOperation $node,
		BaseExpression $left_expr,
		BaseType $left_type,
		BaseExpression $right_expr,
		BaseType $right_type
	): void {
		$operator = $node->operator;
		if (!$operator->is(OPID::EQUAL)
			&& !$operator->is(OPID::IDENTICAL)
			&& !$operator->is(OPID::NOT_EQUAL)
			&& !$operator->is(OPID::NOT_IDENTICAL)) {
			return;
		}

		$is_not = $operator->is(OPID::NOT_EQUAL) || $operator->is(OPID::NOT_IDENTICAL);
		if ($left_expr instanceof Identifiable && TypeHelper::is_invalidable_sentinel($left_type, $right_expr)) {
			$this->set_type_assertion_for($node, new IsOperation($left_expr, $left_type, $is_not));
		}
		elseif ($right_expr instanceof Identifiable && TypeHelper::is_invalidable_sentinel($right_type, $left_expr)) {
			$this->set_type_assertion_for($node, new IsOperation($right_expr, $right_type, $is_not));
		}
	}

	private function is_bool_sentinel_union(BaseType $type): bool
	{
		return $type instanceof UnionType
			&& $type->count() > 1
			&& $type->contains_single_type(TypeFactory::$_bool);
	}

	private function assign_false_sentinel_positive_comparison_assertion(
		BinaryOperation $node,
		BaseExpression $target_expr,
		BaseType $target_type,
		BaseType $number_type
	): void {
		if (!$target_expr instanceof Identifiable
			|| !$this->is_number_false_sentinel_union($target_type)
			|| !TypeHelper::is_number_type($number_type)) {
			return;
		}

		$this->set_type_assertion_for($node, new IsOperation($target_expr, TypeFactory::$_bool, true));
	}

	private function is_number_false_sentinel_union(BaseType $type): bool
	{
		if (!$this->is_bool_sentinel_union($type)) {
			return false;
		}

		$number_type = $type->deunite(TypeFactory::$_bool);
		return TypeHelper::is_number_type($number_type);
	}

	private function apply_condition_assertions(BaseExpression $expr, bool $truthy, array &$bindings): void
	{
		if ($expr instanceof PrefixOperation && $expr->operator->is(OPID::BOOL_NOT)) {
			$this->apply_condition_assertions($expr->expression, !$truthy, $bindings);
			return;
		}

		if ($expr instanceof BinaryOperation) {
			if ($truthy && $expr->operator->is(OPID::BOOL_AND)) {
				$this->apply_condition_assertions($expr->left, true, $bindings);
				$this->apply_condition_assertions($expr->right, true, $bindings);
				return;
			}

			if (!$truthy && $expr->operator->is(OPID::BOOL_OR)) {
				$this->apply_condition_assertions($expr->left, false, $bindings);
				$this->apply_condition_assertions($expr->right, false, $bindings);
				return;
			}
		}

		[$assertion, $is_not] = $this->get_type_assertion($expr);
		if ($assertion === null) {
			return;
		}

		if (!$truthy) {
			$is_not = !$is_not;
		}

		$this->apply_single_condition_assertion($assertion, $is_not, $bindings);
	}

	private function apply_single_condition_assertion(IsOperation $assertion, bool $is_not, array &$bindings): void
	{
		if (!$assertion->left instanceof Identifiable) {
			return;
		}

		$left_decl = ASTHelper::get_identifier_symbol($assertion->left)->declaration ?? null;
		if (!$left_decl instanceof IVariableDeclaration) {
			return;
		}

		$key = spl_object_id($left_decl);
		if (!isset($bindings[$key])) {
			$bindings[$key] = [$left_decl, TypeHelper::get_raw_bound_type($left_decl)];
		}

		$left_type = $this->get_assertion_original_type($left_decl) ?? $this->get_unknown_php_value_type();
		$asserting_type = $assertion->right;
		if ($asserting_type instanceof InvalidType && $left_type instanceof InvalidableType) {
			$asserting_type = $left_type;
		}

		if ($asserting_type instanceof InvalidableType) {
			$asserted_type = $is_not
				? TypeHelper::get_invalidable_valid_branch_type($asserting_type)
				: TypeHelper::get_invalidable_sentinel_type($asserting_type);
		}
		elseif ($is_not) {
			if ($left_type instanceof UnionType) {
				$asserted_type = $left_type->deunite($asserting_type);
			}
			elseif ($asserting_type instanceof NoneType) {
				$asserted_type = TypeHelper::to_non_nullable($left_type);
			}
			else {
				return;
			}
		}
		else {
			$asserted_type = $asserting_type;
		}

		$left_decl->bind_type($asserted_type);
	}

	private function restore_condition_assertions(array $bindings): void
	{
		foreach ($bindings as [$decl, $original_type]) {
			TypeHelper::set_raw_bound_type($decl, $original_type);
		}
	}

	private function infer_as_operation(AsOperation $node): BaseType
	{
		$this->infer_expression($node->left);
		$this->check_type($node->right, null);

		$cast_type = $node->right;

		if (!$cast_type instanceof BaseType) {
			throw $this->new_syntax_error("Invalid 'as' expression '{$node->right->name}'", $node);
		}

		return $cast_type;
	}

	private function infer_cast_operation(CastOperation $node): BaseType
	{
		$this->infer_expression($node->left);
		$this->check_type($node->right, null);

		$cast_type = $node->right;
		if (TypeHelper::is_number_type($cast_type)) {
			OutputSafety::set_value_trust($node, ValueTrust::RUNTIME_ENSURED);
		}

		return $cast_type;
	}

	private function infer_is_operation(IsOperation $node): BaseType
	{
		$left_type = $this->infer_expression($node->left);

		$assert_type = $node->right;
		if (!$assert_type instanceof BaseType) {
			if (!$this->is_weakly_checking || !$assert_type instanceof BaseExpression) {
				$kind = $node->right::KIND;
				throw $this->new_syntax_error("Invalid 'is' expression '{$kind}'", $node);
			}

			$this->infer_expression($assert_type);
			return TypeFactory::$_bool;
		}

		if ($assert_type instanceof InvalidType) {
			if (!$left_type instanceof InvalidableType) {
				$left_type_name = self::get_type_name($left_type);
				throw $this->new_syntax_error("Cannot use 'is Invalid' for non-Invalidable type '{$left_type_name}'", $node);
			}

			if ($node->left instanceof Identifiable) {
				$this->set_type_assertion_for($node, $node);
			}

			return TypeFactory::$_bool;
		}

		$this->check_type($assert_type);

		if ($node->left instanceof Identifiable) {
			// it self is a type assertion
			$this->set_type_assertion_for($node, $node);
		}

		return TypeFactory::$_bool;
	}

	private function infer_prefix_operation(PrefixOperation $node): BaseType
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
			elseif ($expr_type instanceof UnionType and $expr_type->contains_single_type(TypeFactory::$_uint)) {
				$infered = $expr_type->merge_with_single_type(TypeFactory::$_int);
			}
			else {
				$infered = $expr_type;
			}
		}
		elseif ($operator->is(OPID::IDENTITY)) {
			$this->assert_math_operable($expr_type, $node->expression);
			$infered = $expr_type;
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

	private function infer_postfix_operation(PostfixOperation $node): BaseType
	{
		$expr_type = $this->infer_expression($node->expression);
		return $expr_type;
	}

	private function assert_bitwise_operable(BaseType $type, BaseExpression $node)
	{
		if ($this->is_weakly_checking && $this->is_php_dynamic_top_type($type)) {
			return;
		}

		if (!$type instanceof UIntType && !$type instanceof IntType && !$type instanceof FloatType && !$type instanceof StringType) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Bitwise operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function is_php_dynamic_top_type(BaseType $type): bool
	{
		return PHPWeakPolicy::is_dynamic_top_type($type);
	}

	private function assert_math_operable(BaseType $type, BaseExpression $node)
	{
		if (!TypeHelper::is_number_type($type, $node) && $this->should_report_math_mismatch($type, $node)) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Math operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function should_report_math_mismatch(BaseType $type, BaseExpression $node): bool
	{
		if (!$this->is_weakly_checking) {
			return true;
		}

		return $node instanceof LiteralString && !is_numeric($node->value);
	}

	private function assert_bool_operable(BaseType $type, BaseExpression $node)
	{
		if (!$this->is_weakly_checking && !$this->is_bool_operable_type($type)) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Bool operation cannot use for '$type_name' type expression", $node);
		}
	}

	private function is_bool_operable_type(BaseType $type): bool
	{
		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if (!$this->is_bool_operable_type($member)) {
					return false;
				}
			}

			return true;
		}

		return $type instanceof BoolType
			|| $type instanceof UIntType
			|| $type instanceof IntType;
	}

	private function infer_none_coalescing_expression(NoneCoalescingOperation $node): BaseType
	{
		$prev_allow_none_key_access = $this->allow_none_coalescing_key_access;
		$this->allow_none_coalescing_key_access = true;
		$left_infered = $this->infer_expression($node->left);
		$this->allow_none_coalescing_key_access = $prev_allow_none_key_access;
		$right_infered = $this->infer_expression($node->right);

		return $this->infer_none_coalesced_type($left_infered, $right_infered);
	}

	private function infer_none_coalesced_type(BaseType $left_infered, BaseType $right_infered): BaseType
	{
		$reduced_type = $this->reduce_types([$left_infered, $right_infered]);

		if ($reduced_type instanceof MixedType) {
			return $reduced_type;
		}

		// none has been coalesced
		if (!$right_infered instanceof NoneType) {
			// Avoid affecting previously defined
			// if (!$reduced_type instanceof UnionType) {
				$reduced_type = clone $reduced_type;
			// }

			$reduced_type = TypeHelper::to_non_nullable($reduced_type);
		}

		return $reduced_type;
	}

	private function infer_ternary_expression(TernaryExpression $node): BaseType
	{
		$condition = $node->condition;
		$condition_type = $this->infer_expression($condition);

		[$assertion, $is_not] = $this->get_type_assertion($condition);
		if ($assertion) {
			$infereds = $this->infer_ternary_with_assertion($node, $condition_type, $assertion, $is_not);
		}
		else {
			if ($node->then === null) {
				$then_type = $this->get_php_truthy_condition_type($condition_type);
			}
			else {
				$then_type = $this->infer_reachable_ternary_branch($node->then);
			}

			$else_type = $this->infer_reachable_ternary_branch($node->else);

			$infereds = [$then_type, $else_type];
		}

		return $this->reduce_reachable_ternary_types($infereds);
	}

	private function infer_reachable_ternary_branch(BaseExpression $node): ?BaseType
	{
		if ($node instanceof ThrowExpression) {
			$this->infer_expression($node);
			return null;
		}

		return $this->infer_expression($node);
	}

	private function reduce_reachable_ternary_types(array $types): BaseType
	{
		$reachable_types = array_values(array_filter($types));
		return $reachable_types
			? $this->reduce_types($reachable_types)
			: TypeFactory::$_any;
	}

	private function get_php_truthy_condition_type(BaseType $type): BaseType
	{
		if ($type instanceof InvalidableType && $type->sentinel instanceof LiteralBoolean && $type->sentinel->value === false) {
			return TypeHelper::get_invalidable_valid_branch_type($type);
		}

		return TypeHelper::to_non_nullable($type);
	}

	private function infer_ternary_with_assertion(TernaryExpression $node, BaseType $condition_type, IsOperation $type_assertion, bool $is_not): array
	{
		$assertion_left = $type_assertion->left;
		$left_decl = $assertion_left instanceof Identifiable
			? (ASTHelper::get_identifier_symbol($assertion_left)->declaration ?? null)
			: null;
		if (!$left_decl instanceof IVariableDeclaration) {
			$then_type = $node->then === null
				? $condition_type
				: $this->infer_reachable_ternary_branch($node->then);
			$else_type = $this->infer_reachable_ternary_branch($node->else);

			return [$then_type, $else_type];
		}

		$asserted_then_type = null;
		$asserted_else_type = null;

		$left_original_type = TypeHelper::get_bound_type($left_decl);
		$asserting_type = $type_assertion->right;

		if ($is_not) {
			$asserted_then_type = $this->get_type_without_assertion($left_original_type, $asserting_type);
			$asserted_else_type = $this->get_asserted_branch_type($asserting_type);
		}
		else {
			$asserted_else_type = $this->get_type_without_assertion($left_original_type, $asserting_type);
			$asserted_then_type = $this->get_asserted_branch_type($asserting_type);
		}

		if ($node->then === null) {
			$then_type = $asserted_then_type ?? $this->get_php_truthy_condition_type($condition_type);
		}
		else {
			// it would infer with the asserted then type
			$asserted_then_type and $left_decl->bind_type($asserted_then_type);
			$then_type = $this->infer_reachable_ternary_branch($node->then);
		}

		// it would infer with the asserted else type
		$left_decl->bind_type($asserted_else_type ?? $left_original_type);
		$else_type = $this->infer_reachable_ternary_branch($node->else);

		// reset to original type
		$left_decl->bind_type($left_original_type);

		return [$then_type, $else_type];
	}

	private function infer_array_expression(ArrayExpression $node): BaseType
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

	private function infer_dict_expression(DictExpression $node): BaseType
	{
		if (!$node->items) {
			return TypeFactory::$_dict;
		}

		$infered_value_types = [];
		$known_member_types = [];
		foreach ($node->items as $item) {
			if ($item instanceof DictMember) {
				$infered = $this->infer_dict_member($item);
				$key = $this->get_literal_dict_key($item->key);
				if ($key !== null) {
					$known_member_types[$key] = $infered;
				}
			}
			else {
				$infered = $this->infer_expression($item);
			}

			$infered_value_types[] = $infered;
		}

		$generic_type = $this->reduce_types($infered_value_types);

		return TypeFactory::create_dict_type($generic_type, $known_member_types);
	}

	private function get_literal_dict_key(BaseExpression $key): ?string
	{
		if ($key instanceof LiteralString) {
			return 's:' . $key->value;
		}

		if ($key instanceof LiteralInteger) {
			return 'i:' . (string)intval($key->value, 0);
		}

		return null;
	}

	private function infer_dict_member(DictMember $item)
	{
		$key_type = $this->infer_expression($item->key);
		if (!TypeHelper::is_dict_key_type($key_type) && $this->should_report_dict_key_mismatch($key_type)) {
			$type_name = $this->get_type_name($key_type);
			throw $this->new_syntax_error("Key type for Dict should be String/Int, {$type_name} given", $item->key);
		}

		return $this->infer_expression($item->value);
	}

	private function should_report_dict_key_mismatch(BaseType $key_type): bool
	{
		if (!$this->is_weakly_checking) {
			return true;
		}

		return $key_type instanceof ObjectType;
	}

	private function infer_object_expression(ObjectExpression $node): BaseType
	{
		$symbol = ASTHelper::get_object_expression_symbol($node);
		$this->check_classkindred_declaration($symbol->declaration);

		$infered = clone TypeFactory::$_object;
		TypeHelper::set_type_symbol($infered, $symbol);

		return $infered;
	}

	private function infer_callback_argument(CallbackArgument $node): BaseType
	{
		$value_expr = $node->value;
		if ($value_expr instanceof AnonymousFunction) {
			$this->infer_anonymous_function($value_expr);
			$decl = $value_expr;
		}
		else {
			$this->infer_expression($value_expr);
			$decl = $value_expr instanceof Identifiable
				? ASTHelper::get_identifier_symbol($value_expr)->declaration
				: null;
		}

		$infered = $decl->get_expressed_type();
		return $infered;
	}

	/**
	 * @param BaseType $type
	 * @param IDeclaration|BaseExpression|null $node
	 */
	private function check_type(BaseType $type, $node = null)
	{
		if ($type instanceof InvalidType) {
			return;
		}

		if ($type instanceof TypeReference) {
			// $infered = $this->infer_plain_identifier($type);
			$decl = $this->get_actual_declaration_for_identifier($type);
			if (!$decl instanceof ClassKindredDeclaration) {
				$name = $this->get_declaration_name($decl);
				throw $this->new_syntax_error("Cannot use '$name' as a type reference", $type);
			}
		}
		elseif ($type instanceof BaseType) {
			if (!TypeHelper::get_type_symbol($type)) {
				TypeHelper::set_type_symbol($type, $this->find_type_symbol_and_check_declaration($type));
			}

			if ($type instanceof SingleGenericType) {
				// check the value type
				$type->generic_type !== null && $this->check_type($type->generic_type);
			}
			elseif ($type instanceof UnionType) {
				foreach ($type->get_members() as $member) {
					$this->check_type($member);
				}
			}
			elseif ($type instanceof IntersectionType) {
				foreach ($type->get_members() as $member) {
					$this->check_type($member);
				}
			}
			elseif ($type instanceof CallableType) {
				$this->check_callable_type($type);
			}

			// no any other need to check
		}
		else {
			$kind = $type::KIND;
			throw $this->new_syntax_error("Unknow type kind '$kind'", $type);
		}
	}

	private function check_callable_type(CallableType $node)
	{
		$this->mark_checked($node);

		$hinted = $this->get_and_check_hinted_type($node);
		$node->infered_type = $hinted ?? TypeFactory::$_void;

		$node->parameters and $this->check_parameters_for_callable_declaration($node);
	}

	private function infer_constant_identifier(ConstantIdentifier $node): BaseType
	{
		$decl = $this->get_actual_declaration_for_identifier($node);
		return $decl->get_expressed_type();
	}

	private function infer_yield_expression(YieldExpression $node): BaseType
	{
		$this->infer_expression($node->argument);
		return $this->get_unknown_php_value_type();
	}

	private function infer_throw_expression(ThrowExpression $node): BaseType
	{
		$this->infer_expression($node->argument);
		return TypeFactory::$_any;
	}

	private function infer_include_expression(IncludeExpression $node): ?BaseType
	{
		$infered = $this->infer_expression($node->target);
		$infered = TypeHelper::unwrap_excludable_type($infered);
		if (!$infered instanceof StringType
			&& !$this->allow_dynamic_include_target($infered)) {
			throw $this->new_syntax_error("Expected String type expression", $node->target);
		}

		return $this->get_unknown_php_value_type();
	}

	private function allow_dynamic_include_target(BaseType $type): bool
	{
		return $this->is_weakly_checking
			&& ($type instanceof AnyType
				|| $type instanceof MixedType
				|| ($type instanceof UnionType && $this->is_string_key_accessible_union($type)));
	}

	private function infer_new_expression(InstancingExpression $node): BaseType
	{
		$callee = $node->callee;

		// the endmost declaration, some times maybe not the direct
		if ($callee instanceof PlainIdentifier) {
			$decl = $this->get_checked_classkindred_declaration($callee);
		}
		else {
			$decl = $this->get_class_declaration_for_expr($callee);
		}

		$infered = $this->infer_instancing_expr($callee, $decl);

		$this->check_call_arguments($node, $decl);

		return $infered;
	}

	private function infer_instancing_expr(BaseExpression $callee, BaseDeclaration $decl)
	{
		if (!$decl instanceof ClassDeclaration) {
			throw $this->new_syntax_error("Cannot instantiate '{$decl->name}'", $callee);
		}

		return $decl->typing_identifier;
	}

	private function infer_call_expression(CallExpression $node): BaseType
	{
		$callee_name = $this->get_plain_callee_name($node->callee);
		if ($this->is_weakly_checking && in_array($callee_name, ['empty', 'isset', 'unset'], true)) {
			return $this->infer_php_soft_key_access_call($node, $callee_name);
		}

		return $this->infer_basecall_expression($node);
	}

	private function infer_php_soft_key_access_call(CallExpression $node, string $callee_name): BaseType
	{
		$allow_php_soft_key_access = $this->allow_php_soft_key_access;
		$this->allow_php_soft_key_access = true;
		try {
			foreach ($node->arguments as $argument) {
				$this->infer_expression($argument);
			}
		}
		finally {
			$this->allow_php_soft_key_access = $allow_php_soft_key_access;
		}

		return $callee_name === 'unset' ? TypeFactory::$_void : TypeFactory::$_bool;
	}

	private function infer_pipecall_expression(PipeCallExpression $node): BaseType
	{
		return $this->infer_basecall_expression($node);
	}

	private function infer_first_class_callable_expression(FirstClassCallableExpression $node): BaseType
	{
		$infered = $this->infer_expression($node->callee);
		if ($infered instanceof CallableType) {
			return $infered;
		}

		if ($this->allow_dynamic_first_class_callable($infered)) {
			return TypeFactory::$_callable;
		}

		$type_name = $this->get_type_name($infered);
		throw $this->new_syntax_error("Expected callable expression, got '$type_name'", $node);
	}

	private function allow_dynamic_first_class_callable(BaseType $type): bool
	{
		return $this->is_weakly_checking && $this->is_php_dynamic_top_type($type);
	}

	private function allow_dynamic_callable_invocation(BaseType $type): bool
	{
		return $this->is_weakly_checking && $this->is_php_dynamic_top_type($type);
	}

	private function infer_basecall_expression(BaseCallExpression $node): BaseType
	{
		$callee = $node->callee;

		// the endmost declaration, some times maybe not the direct
		$callable_decl = $this->require_callee_declaration($callee);

		if ($callable_decl instanceof ClassKindredDeclaration) {
			$infered = $this->infer_instancing_expr($callee, $callable_decl);
			// instancing arguments
			$this->check_call_arguments($node, $callable_decl);
		}
		elseif ($callable_decl === TypeFactory::$_callable or $callable_decl->is_virtual) {
			// the Any-Callable type do not need to match parameters
			foreach ($node->arguments as $argument) {
				$this->infer_expression($argument);
			}

			$infered = $this->get_unknown_php_value_type();
		}
		elseif ($callable_decl instanceof ICallableDeclaration) {
			if ($this->should_check_callable_declaration($callable_decl)) {
				$this->is_checked($callable_decl) or $this->check_callable_declaration($callable_decl);
			}
			// function return type
			$infered = $callable_decl->get_expressed_type();
			// function calling arguments
			$this->check_call_arguments($node, $callable_decl);
		}
		else {
			throw $this->new_syntax_error("Callee not a valid callable declaration", $callee);
		}

		if ($infered === null) {
			if ($this->is_weakly_checking) {
				$infered = $this->get_unknown_php_value_type();
			}
			else {
				throw $this->new_syntax_error("Unable to infer return type, there may have been a recursive call", $node);
			}
		}

		$infered = $this->rebind_self_return_type_for_call($infered, $callee);

		// for render
		ASTHelper::set_callee_declaration($node, $callable_decl);
		$this->mark_call_value_trust($node, $callable_decl);

		return $infered;
	}

	private function mark_call_value_trust(BaseCallExpression $node, IDeclaration $decl): void
	{
		$this->mark_declaration_value_trust($node, $decl, true);
	}

	private function mark_member_value_trust(AccessingIdentifier $node, IDeclaration $decl): void
	{
		$this->mark_declaration_value_trust($node, $decl);
	}

	private function mark_declaration_value_trust(BaseExpression $node, IDeclaration $decl, bool $callable_only = false): void
	{
		if (!$decl instanceof BaseDeclaration) {
			return;
		}

		if ($callable_only && !$decl instanceof ICallableDeclaration) {
			return;
		}

		if ($decl->noted_type_from_phpdoc) {
			OutputSafety::set_value_trust($node, ValueTrust::PHPDOC_ONLY);
			return;
		}

		if ($this->is_local_checked_declaration($decl)) {
			OutputSafety::set_value_trust($node, ValueTrust::CHECKED_LOCAL);
			return;
		}

		if ($this->is_trusted_unit_declaration($decl)) {
			OutputSafety::set_value_trust($node, ValueTrust::TRUSTED_DECLARATION);
			return;
		}

		if ($decl->program?->source_dialect === Program::SOURCE_DIALECT_HEADER) {
			OutputSafety::set_value_trust($node, ValueTrust::HEADER_ONLY);
		}
	}

	private function is_local_checked_declaration(BaseDeclaration $decl): bool
	{
		$program = $decl->program;
		return !$decl->is_extern
			&& $program !== null
			&& $program->unit === $this->unit
			&& $program->source_dialect !== Program::SOURCE_DIALECT_HEADER;
	}

	private function is_trusted_unit_declaration(BaseDeclaration $decl): bool
	{
		if ($decl->belong_block instanceof ClassKindredDeclaration) {
			return $this->is_trusted_unit_declaration($decl->belong_block);
		}

		$unit = $decl->program?->unit;
		if ($unit !== null) {
			return $unit->is_trusted;
		}

		return ($this->builtin_unit !== null
				&& $this->builtin_unit->is_trusted
				&& $this->get_symbol_in_unit($this->builtin_unit, $decl->name)?->declaration === $decl)
			|| ($decl instanceof RootDeclaration && $decl->ns?->uri === _BUILTIN_NS);
	}

	private function rebind_self_return_type_for_call(BaseType $type, BaseExpression $callee): BaseType
	{
		if (!$callee instanceof AccessingIdentifier || !$this->contains_self_type($type)) {
			return $type;
		}

		$receiver_type = ASTHelper::get_expressed_type($callee->basing) ?? $this->infer_expression($callee->basing);
		if ($receiver_type instanceof MetaType) {
			$receiver_type = $receiver_type->generic_type;
		}

		return $this->replace_self_type($type, $receiver_type);
	}

	private function contains_self_type(BaseType $type): bool
	{
		if ($type instanceof SelfType || $type->name === _TYPE_SELF) {
			return true;
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if ($this->contains_self_type($member)) {
					return true;
				}
			}
		}
		elseif ($type instanceof InvalidableType) {
			return $this->contains_self_type($type->valid_type);
		}

		return false;
	}

	private function replace_self_type(BaseType $type, BaseType $receiver_type): BaseType
	{
		if ($type instanceof SelfType || $type->name === _TYPE_SELF) {
			return $receiver_type;
		}

		if ($type instanceof UnionType) {
			$members = [];
			foreach ($type->get_members() as $member) {
				$members[] = $this->replace_self_type($member, $receiver_type);
			}
			return TypeFactory::create_union_type($members);
		}

		if ($type instanceof InvalidableType) {
			return TypeFactory::create_invalidable_type($this->replace_self_type($type->valid_type, $receiver_type), $type->sentinel);
		}

		return $type;
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
		$first_unpack_argument_index = null;

		$normalizeds = [];
		foreach ($arguments as $key => $argument) {
			if (is_numeric($key)) {
				$parameter = $parameters[$key] ?? $last_variadic_param;
				if (!$parameter) {
					if ($this->is_weakly_checking) {
						$this->infer_expression($argument);
						$normalizeds[$key] = $argument;
						continue;
					}

					if ($callee_decl instanceof BaseDeclaration) {
						$declar_name = $this->get_declaration_name($callee_decl);
					}
					else {
						$declar_name = 'callable';
					}
					throw $this->new_syntax_error("Argument $key does not matched the parameter defined in '{$declar_name}'", $argument);
				}

				$idx = $key;
			}
			else {
				$has_named_arguments = true;
				list($idx, $parameter) = $this->require_parameter_by_name($key, $parameters, $node->callee);
			}

			if ($first_unpack_argument_index === null && $this->is_argument_unpack($argument)) {
				$first_unpack_argument_index = $idx;
			}

			// check type is match
			$param_type = $parameter->get_expressed_type();
			// if ($param_type === null) {
			// 	throw $this->new_syntax_error('Unexpected parameter type', $argument);
			// }

			$infered = $this->infer_expression($argument);
			if (!$this->is_type_compatible($param_type, $infered, $argument, 'argument') && $this->should_report_type_mismatch($param_type, $infered, $argument, 'argument')) {
				if ($this->warn_phpdoc_only_type_mismatch($parameter, $param_type, $infered, $argument, 'argument')) {
					$normalizeds[$idx] = $argument;
					continue;
				}

				if ($callee_decl instanceof BaseDeclaration) {
					$callee_name = self::get_declaration_name($callee_decl);
				}
				else {
					$callee_name = 'callable';
				}
				$expected_name = self::get_type_name($param_type);
				$infered_name = self::get_type_name($infered);

				if (!is_int($key)) {
					$key = "'$key'";
				}

				// dump($param_type, $infered);exit;
				throw $this->new_syntax_error("Type of argument $key does not matched the parameter, expected {$expected_name}, {$infered_name} given", $argument);
			}

			if ($parameter->is_inout) {
				if (!ASTHelper::is_assignable_expr($argument)) {
					throw $this->new_syntax_error("Argument $key is final(un-reassignable), cannot use for inout parameter", $argument);
				}

				$this->rebind_inout_argument_type($argument, $parameter, $param_type);
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
			if ($first_unpack_argument_index !== null && $idx >= $first_unpack_argument_index) {
				continue;
			}

			if ($param->value === null && !$param->is_variadic && !isset($normalizeds[$idx])) {
				$callee_name = self::get_declaration_name($callee_decl);
				$param_name = $param->name;
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
			ASTHelper::set_normalized_arguments($node, $normalizeds);
		}
	}

	private function is_argument_unpack(BaseExpression $argument): bool
	{
		return $argument instanceof PrefixOperation && $argument->operator->is(OPID::SPREAD);
	}

	private function rebind_inout_argument_type(BaseExpression $argument, ParameterDeclaration $parameter, BaseType $param_type): void
	{
		if (!$argument instanceof Identifiable) {
			return;
		}

		$decl = ASTHelper::get_identifier_symbol($argument)?->declaration ?? null;
		if (!$decl instanceof IVariableDeclaration) {
			return;
		}

		$current_type = TypeHelper::get_bound_type($decl);
		if (!$current_type instanceof NoneType && !$current_type instanceof AnyType) {
			return;
		}

		$rebound_type = $parameter->value !== null && TypeHelper::is_nullable_type($param_type)
			? TypeHelper::to_non_nullable($param_type)
			: $param_type;
		$this->rebind_type_for_variable($decl, $rebound_type);
	}

	private function merge_callbacks_to_arguments(array &$arguments, array $callbacks, array $parameters)
	{
		if (count($callbacks) === 1 && $callbacks[0]->name === null) {
			$first_callback_parameter_on_tail = null;
			for ($i = count($parameters) - 1; $i >=0; $i--) {
				$parameter = $parameters[$i];
				$param_type = $parameter->get_expressed_type();
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
			return null; // no any to check
		}

		return $symbol->declaration;
	}

	private function assert_type_compatible(BaseType $left, BaseType $right, Node $value_node, string $kind = 'assign')
	{
		if (!$this->is_type_compatible($left, $right, $value_node, $kind) && $this->should_report_type_mismatch($left, $right, $value_node, $kind)) {
			if ($left === TypeFactory::$_none) {
				throw $this->new_syntax_error("It's required a type hint", $value_node);
			}

			$left_type_name = self::get_type_name($left);
			$right_type_name = self::get_type_name($right);

			throw $this->new_syntax_error("It's not compatible for type {$left_type_name}, {$kind} with {$right_type_name}", $value_node);
		}
	}

	private function is_type_compatible(BaseType $left, BaseType $right, Node $value_node, string $kind)
	{
		if ($this->is_weakly_checking
			&& $value_node instanceof InstancingExpression
			&& $this->is_dynamic_instancing_value($value_node)
		) {
			return true;
		}

		if ($this->is_type_compatible_for_kind($left, $right, $kind)) {
			return true;
		}

		// for [], [:]
		if ($value_node instanceof IArrayLikeExpression && !$value_node->items) {
			return true;
		}

		return false;
	}

	private function is_type_compatible_for_kind(BaseType $left, BaseType $right, string $kind): bool
	{
		return match ($kind) {
			'argument' => TypeHelper::is_argument_compatible($left, $right),
			'return' => TypeHelper::is_return_compatible($left, $right),
			default => TypeHelper::is_assignment_compatible($left, $right),
		};
	}

	private function is_dynamic_instancing_value(InstancingExpression $value_node): bool
	{
		$callee = $value_node->callee;
		if ($callee instanceof VariableIdentifier) {
			return true;
		}

		if (!$callee instanceof PlainIdentifier) {
			return true;
		}

		return $this->is_dynamic_instancing_identifier($callee);
	}

	private function is_dynamic_instancing_identifier(PlainIdentifier $callee): bool
	{
		$decl = $this->get_actual_declaration_for_identifier($callee);
		return $decl instanceof IVariableDeclaration;
	}

	private function should_report_type_mismatch(BaseType $expected, BaseType $actual, Node $value_node, string $kind = 'assign'): bool
	{
		if (!$this->is_weakly_checking) {
			return true;
		}

		return TypeHelper::should_report_php_weak_type_mismatch($expected, $actual, $value_node, $kind);
	}

	private function warn_phpdoc_only_type_mismatch(?BaseDeclaration $decl, BaseType $expected, BaseType $actual, Node $value_node, string $kind): bool
	{
		if (!$this->is_weakly_checking
			|| !$decl instanceof BaseDeclaration
			|| $decl->declared_type !== null
			|| !ASTHelper::is_noted_type_from_phpdoc($decl)) {
			return false;
		}

		if ($this->is_type_compatible($expected, $actual, $value_node, $kind)
			|| !TypeHelper::should_report_php_weak_type_mismatch($expected, $actual, $value_node, $kind)) {
			return false;
		}

		$expected_name = self::get_type_name($expected);
		$actual_name = self::get_type_name($actual);
		$this->add_syntax_warning("PHPDoc {$kind} type mismatch; expected {$expected_name}, {$actual_name} given; degrading to warning in PHP weak mode", $value_node);
		return true;
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

	protected function infer_plain_identifier(PlainIdentifier $node): BaseType
	{
		$decl = $this->get_actual_declaration_for_identifier($node);

		if ($decl instanceof IVariableDeclaration) {
			$type = TypeHelper::get_bound_type($decl);
		}
		elseif ($decl instanceof ConstantDeclaration || $decl instanceof ClassKindredDeclaration) {
			$type = $decl->get_expressed_type();
		}
		elseif ($decl instanceof CallableType) {
			$type = $decl;
		}
		elseif ($decl instanceof ICallableDeclaration) {
			$return_type = $decl->get_expressed_type();
			$type = TypeFactory::create_callable_type($return_type, $decl->parameters);
		}
		// elseif ($decl instanceof NamespaceDeclaration) {
		// 	$type = TypeFactory::$_namespace;
		// }
		else {
			throw $this->new_syntax_error("Undexpected declaration for identifier '{$node->name}'", $node);
		}

		return $type;
	}

	private function infer_accessing_identifier(AccessingIdentifier $node, bool $ignore_none = false): BaseType
	{
		$basing_type = $this->infer_expression($node->basing);
		if ($basing_type instanceof UnionType) {
			if ($ignore_none || ($this->is_weakly_checking && TypeHelper::is_nullable_type($basing_type))) {
				$basing_type = TypeHelper::to_non_nullable($basing_type);
			}

			if ($basing_type instanceof UnionType) {
				// Check if all member types have the requested member
				$infered_types = [];
				foreach ($basing_type->get_members() as $member_type) {
					try {
						$member = $this->require_accessing_identifier_declaration_for_type($member_type, $node);
						$infered_types[] = $this->get_member_type($member);
					}
					catch (\Throwable $e) {
						// Member not found in one of the union types
						if ($this->is_weakly_checking) {
							return $this->get_unknown_php_value_type();
						}
						$type_name = $this->get_type_name($basing_type);
						throw $this->new_syntax_error("Cannot accessing '$node->name' to '$type_name'", $node);
					}
				}
				
				// All members have the property, return union of their types
				if (count($infered_types) === 1) {
					return $infered_types[0];
				}
				return TypeFactory::create_union_type($infered_types);
			}
		}

		$member = $this->require_accessing_identifier_declaration($basing_type, $node);

		switch ($member::KIND) {
			case MethodDeclaration::KIND:
				$this->is_checked($member) or $this->check_method_declaration($member);
				$infered = TypeFactory::create_callable_type($member->get_expressed_type(), $member->parameters);
				break;
			case FunctionDeclaration::KIND:
				$this->is_checked($member) or $this->check_function_declaration($member);
				$infered = TypeFactory::create_callable_type($member->get_expressed_type(), $member->parameters);
				break;

			case MemberMappingDeclaration::KIND:
				if (!$member->is_property) {
					throw $this->new_syntax_error("Cannot use the member mapping function '$member->name' without '()'", $node);
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

			// case NamespaceDeclaration::KIND:
			// 	$infered = TypeFactory::$_namespace;
			// 	break;

			default:
				throw $this->new_syntax_error("Unexpected expression", $node);
		}

		$this->mark_member_value_trust($node, $member);

		if ($this->is_weakly_checking && ($infered instanceof TypeReference || $infered instanceof UnionType)) {
			$this->check_type($infered, $node);
		}

		return $infered;
	}

	private function get_member_type(IDeclaration $member): BaseType
	{
		switch ($member::KIND) {
			case MethodDeclaration::KIND:
			case FunctionDeclaration::KIND:
				return TypeFactory::create_callable_type($member->get_expressed_type(), $member->parameters);

			case MemberMappingDeclaration::KIND:
				if (!$member->is_property) {
					return $this->get_unknown_php_value_type();
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
				return $this->get_unknown_php_value_type();
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
			$basing_decl = $this->get_actual_class_declaration_for_metatype_expr($basing_type);
			$this->set_symbol_for_symbolic_node($node, $this->require_class_member_symbol($basing_decl, $node));
		}
		else {
			throw $this->new_syntax_error("Invalid accessable type", $node->basing);
		}

		return $this->get_symbol_for_symbolic_node($node)->declaration;
	}

	private function infer_regular_expression(RegularExpression $node): BaseType
	{
		return TypeFactory::$_regex;
	}

	private function infer_plain_interpolated_string(PlainInterpolatedString $node): BaseType
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

		return $is_pure ? TypeFactory::$_plain : TypeFactory::$_string;
	}

	private function infer_escaped_interpolated_string(EscapedInterpolatedString $node): BaseType
	{
		foreach ($node->items as $item) {
			if ($item instanceof StringInterpolation) {
				$infered = $this->infer_interpolation($item);
				// $item->infered_type = $infered;
			}
		}

		return TypeFactory::$_string;
	}

	private function infer_variable_identifier(VariableIdentifier $node): BaseType
	{
		$decl = $this->get_actual_declaration_for_identifier($node);
		return TypeHelper::get_bound_type($decl);
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
				if (!TypeHelper::is_string_concatable_type($infered)) {
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
				throw $this->new_syntax_error("Type of activity attributes expression must be Dict<String>", $dyn_expr);
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
					throw $this->new_syntax_error("Expect String/IView/List<IView> type value, {$type_name} given", $item);
				}

				// $item->infered_type = $infered;
			}
			elseif ($item instanceof XTagElement) {
				// text/comment
			}
			else {
				// normal expression
				$infered = $this->infer_expression($item);
				if (!TypeHelper::is_string_concatable_type($infered)) {
					$type_name = self::get_type_name($infered);
					throw $this->new_syntax_error("Expect scalar type value, {$type_name} given", $item);
				}
			}
		}

		return TypeFactory::$_xview;
	}

	protected function find_type_symbol_and_check_declaration(BaseType $identifier)
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

	private function get_symbol_for_symbolic_node(Identifiable|BaseType $node): ?Symbol
	{
		return $node instanceof BaseType
			? TypeHelper::get_type_symbol($node)
			: ASTHelper::get_identifier_symbol($node);
	}

	private function set_symbol_for_symbolic_node(Identifiable|BaseType $node, ?Symbol $symbol): void
	{
		if ($node instanceof BaseType) {
			TypeHelper::set_type_symbol($node, $symbol);
		}
		else {
			ASTHelper::set_identifier_symbol($node, $symbol);
		}
	}

	private function get_symbol_for_super_identifier(PlainIdentifier $identifier)
	{
		$current_method = $this->function;
		$super_identifier = $current_method->belong_block->extends[0] ?? null;
		if ($super_identifier === null) {
			if ($this->is_weakly_checking && $current_method->belong_block instanceof TraitDeclaration) {
				return $this->create_symbol_for_trait_host_super($current_method);
			}

			throw $this->new_syntax_error("There are not extends a class/interface for 'super' reference", $identifier);
		}

		$super_class = $this->get_symbol_for_symbolic_node($super_identifier)->declaration;
		if ($current_method->is_static) {
			$symbol = $super_class->this_class_symbol;
		}
		else {
			$symbol = $super_class->this_object_symbol;
		}

		return $symbol;
	}

	private function create_symbol_for_trait_host_super(IFunctionDeclaration $current_method): Symbol
	{
		[$super_class, ] = $this->factory->create_virtual_class('__TRAIT_HOST_PARENT__', $this->program);

		return $current_method->is_static
			? $super_class->this_class_symbol
			: $super_class->this_object_symbol;
	}

	private function get_actual_declaration_for_identifier(Identifiable|TypeReference $identifier)
	{
		$symbol = $this->get_symbol_for_symbolic_node($identifier);
		if ($symbol === null) {
			if ($identifier->name === _SUPER) {
				$symbol = $this->get_symbol_for_super_identifier($identifier);
				$this->set_symbol_for_symbolic_node($identifier, $symbol);
			}
			elseif ($this->is_weakly_checking && ($identifier instanceof PlainIdentifier || $identifier instanceof TypeReference)) {
				$symbol = $this->try_create_virtual_symbol($identifier);
				$this->set_symbol_for_symbolic_node($identifier, $symbol);
			}
			else {
				throw $this->new_syntax_error('Missed symbol', $identifier);
			}
		}

		$decl = $symbol->declaration;
		if ($decl instanceof UseDeclaration) {
			$decl = $this->resolve_use_symbol_declaration($symbol, $identifier);
			if ($decl === null) {
				if ($this->is_weakly_checking) {
					$symbol = $this->try_create_virtual_symbol($identifier);
					$this->set_symbol_for_symbolic_node($identifier, $symbol);
					return $symbol->declaration;
				}

				throw $this->new_syntax_error('Missed symbol', $identifier);
			}
		}

		if (!$this->is_checked($decl)) {
			if (!$this->is_current_function_owner_declaration($decl)) {
				$this->check_declaration($decl);
			}
		}

		return $decl;
	}

	private function is_current_function_owner_declaration(IDeclaration $decl): bool
	{
		return $this->function instanceof IFunctionDeclaration
			&& $this->function->belong_block === $decl;
	}

	private function check_declaration_for_symbol(Symbol $symbol)
	{
		$decl = $symbol->declaration;
		if ($decl instanceof UseDeclaration) {
			$decl = $this->resolve_use_symbol_declaration($symbol);
			if ($decl === null) {
				return;
			}
		}

		if (!$this->is_checked($decl)) {
			$this->check_declaration($decl);
		}
	}

	private function resolve_use_symbol_declaration(Symbol $symbol, Identifiable|TypeReference|null $identifier = null): ?BaseDeclaration
	{
		$use = $symbol->declaration;
		if (!$use instanceof UseDeclaration) {
			return $use;
		}

		if (!$this->is_checked($use)) {
			$this->check_use_target($use);
		}

		$source = ASTHelper::get_use_source_declaration($use);
		if ($source instanceof BaseDeclaration) {
			$symbol->declaration = $source;
			$symbol->using = $use;
			return $source;
		}

		if ($this->is_weakly_checking && $identifier instanceof TypeReference) {
			$virtual_symbol = $this->try_create_virtual_symbol($identifier);
			$virtual_symbol->using = $use;
			$this->set_symbol_for_symbolic_node($identifier, $virtual_symbol);
			return $virtual_symbol->declaration;
		}

		return null;
	}

	private function find_symbol_for_type(BaseType $type)
	{
		$name = $type->name;

		$symbol = ASTHelper::get_scope_symbol($this->program, $name)
			?? $this->get_symbol_in_unit($this->unit, $name)
			?? ($this->builtin_unit ? $this->get_symbol_in_unit($this->builtin_unit, $name) : null);

		return $symbol;
	}

	private function find_symbol_for_plain_identifier(PlainIdentifier|TypeReference $identifier)
	{
		$name = $identifier->name;
		$based_ns = $identifier->ns;

		if ($based_ns) {
			$this->check_namespace($based_ns);
			$based_unit = ASTHelper::get_namespace_based_unit($based_ns);
			if ($based_unit === null) {
				// namespace mode
				$symbol = $this->find_symbol_by_php_use_alias_prefix($based_ns, $name)
					?? $this->find_symbol_by_current_namespace_prefix($based_ns, $name)
					?? $this->find_symbol_in_namespace($based_ns, $name, $this->unit)
					?? ($this->builtin_unit ? $this->find_symbol_in_namespace($based_ns, $name, $this->builtin_unit) : null);
			}
			else {
				// module mode
				$symbol = $this->get_symbol_in_unit($based_unit, $name);
			}
		}
		else {
			$symbol = ASTHelper::get_scope_symbol($this->program, $name)
				?? $this->find_symbol_in_current_namespace($identifier)
				?? $this->get_symbol_in_unit($this->unit, $name)
				?? ($this->builtin_unit ? $this->get_symbol_in_unit($this->builtin_unit, $name) : null);
		}

		return $symbol;
	}

	private function find_symbol_by_current_namespace_prefix(NamespaceIdentifier $ns, string $name): ?Symbol
	{
		if (!$this->program->ns || $this->program->ns->is_global_space()) {
			return null;
		}

		$namepath = $ns->get_namepath();
		if (!$namepath || $namepath[0] === '') {
			return null;
		}

		$expanded_ns = new NamespaceIdentifier(array_merge($this->program->ns->get_namepath(), $namepath));
		return $this->find_symbol_in_namespace($expanded_ns, $name, $this->unit)
			?? ($this->builtin_unit ? $this->find_symbol_in_namespace($expanded_ns, $name, $this->builtin_unit) : null);
	}

	private function find_symbol_by_php_use_alias_prefix(NamespaceIdentifier $ns, string $name): ?Symbol
	{
		$namepath = $ns->get_namepath();
		$alias = array_shift($namepath);
		if ($alias === null) {
			return null;
		}

		$symbol = ASTHelper::get_scope_symbol($this->program, $alias);
		$use_decl = $symbol?->using ?? $symbol?->declaration;
		if (!$use_decl instanceof UseDeclaration || $use_decl->import_kind !== UseDeclaration::IMPORT_CLASS) {
			return null;
		}

		$source_name = $use_decl->source_name ?? $use_decl->target_name ?? $use_decl->name;
		$expanded_names = array_merge($use_decl->ns->get_namepath(), [$source_name], $namepath);
		$expanded_ns = new NamespaceIdentifier($expanded_names);

		return $this->find_symbol_in_namespace($expanded_ns, $name, $this->unit)
			?? ($this->builtin_unit ? $this->find_symbol_in_namespace($expanded_ns, $name, $this->builtin_unit) : null);
	}

	private function find_symbol_in_current_namespace(PlainIdentifier|TypeReference $identifier): ?Symbol
	{
		$ns = $this->program->ns;
		if (!$ns || $ns->is_global_space()) {
			return null;
		}

		return $this->find_symbol_in_namespace($ns, $identifier->name, $this->unit)
			?? ($this->builtin_unit ? $this->find_symbol_in_namespace($ns, $identifier->name, $this->builtin_unit) : null);
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
			ASTHelper::set_namespace_based_unit($ns, $found_unit);
			$new_ns_names = $ns->get_namepath();
			$new_ns_names = array_slice($new_ns_names, count($found_unit->ns->names));
			$ns->set_names($new_ns_names);
		}
	}

	private function get_symbol_in_unit(Unit $unit, string $name)
	{
		$symbol = ASTHelper::get_scope_symbol($unit, $name);
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
		if ($this->is_namespace_unit_root($ns, $unit)) {
			return $this->get_symbol_in_unit($unit, $name);
		}

		$decl = $this->find_namespace_declaration_in_unit($unit, $ns);
		$symbol = $decl ? ASTHelper::get_scope_symbol($decl, $name) : null;
		if ($symbol !== null) {
			return $symbol;
		}

		$relative_ns = $this->get_namespace_relative_to_unit_root($ns, $unit);
		if ($relative_ns === null) {
			return null;
		}

		$decl = $this->find_namespace_declaration_in_unit($unit, $relative_ns);
		return $decl ? ASTHelper::get_scope_symbol($decl, $name) : null;
	}

	private function is_namespace_unit_root(NamespaceIdentifier $ns, Unit $unit): bool
	{
		$unit_ns = $unit->ns;
		return $unit_ns !== null && $ns->get_namepath() === $unit_ns->get_namepath();
	}

	private function get_namespace_relative_to_unit_root(NamespaceIdentifier $ns, Unit $unit): ?NamespaceIdentifier
	{
		$unit_ns = $unit->ns;
		if ($unit_ns === null) {
			return null;
		}

		$namepath = $ns->get_namepath();
		$unit_namepath = $unit_ns->get_namepath();
		if (count($namepath) <= count($unit_namepath)) {
			return null;
		}

		foreach ($unit_namepath as $idx => $part) {
			if (($namepath[$idx] ?? null) !== $part) {
				return null;
			}
		}

		return new NamespaceIdentifier(array_slice($namepath, count($unit_namepath)));
	}

	private function find_namespace_declaration_in_unit(Unit $unit, NamespaceIdentifier $ns)
	{
		$namepath = $ns->get_namepath();
		$ns_name = array_shift($namepath) ?? '';

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
			$basing_type = $this->infer_expression($node->basing);
			$decl = $this->require_accessing_identifier_declaration($basing_type, $node);
		}
		elseif ($node instanceof PlainIdentifier) {
			$decl = $this->require_callable_declaration($node);
		}
		// elseif ($node instanceof ClassKindredIdentifier) {
		// 	$decl = $this->get_checked_classkindred_declaration($node, true);
		// }
		else {
			$type = $this->infer_expression($node);
			$decl = $this->get_callable_declaration_from_type($type);
			if ($decl === null) {
				if ($this->allow_dynamic_callable_invocation($type)) {
					[$decl, $symbol] = $this->factory->create_virtual_function('__php_unknow_func');
				}
				else {
					$type_name = $this->get_type_name(ASTHelper::get_expressed_type($node));
					throw $this->new_syntax_error("Unknow callee type: $type_name", $node);
				}
			}
		}

		if ($decl instanceof ObjectMember) {
			$value = $decl->value;
			if (!$value instanceof IDeclaration) {
				throw $this->new_syntax_error("Invalid callable expression", $node);
			}

			return $value;
		}
		// if is a variable, it's value must be a callable declaration
		// eg. AnonymousFunction
		if ($decl instanceof IVariableDeclaration) {
			$type = TypeHelper::get_bound_type($decl);
			if ($type instanceof MetaType) {
				$type = $type->generic_type;
			}

			return TypeHelper::get_type_symbol($type)->declaration;
		}
		if ($decl instanceof IConstantDeclaration) {
			$type = $decl->get_expressed_type();
			if ($type instanceof MetaType) {
				$type = $type->generic_type;
			}

			return TypeHelper::get_type_symbol($type)->declaration;
		}

		return $decl;
	}

	private function require_callable_declaration(BaseExpression $node): IDeclaration
	{
		$decl = $this->get_actual_declaration_for_identifier($node);

		$return_type = $decl instanceof IVariableDeclaration
			? TypeHelper::get_bound_type($decl)
			: $decl->get_expressed_type();
		$return_type = TypeHelper::unwrap_excludable_type($return_type);
		$callable_type = $this->get_callable_declaration_from_type($return_type);
		if ($decl instanceof ICallableDeclaration) {
			$this->is_checked($decl) or $this->check_callable_declaration($decl);
		}
		elseif ($callable_type !== null) {
			$decl = $callable_type;
		}
		elseif ($return_type instanceof MetaType and $return_type->generic_type instanceof TypeReference) {
			$decl = $this->get_checked_classkindred_declaration($return_type->generic_type, true);
		}
		elseif ($this->allow_dynamic_callable_invocation($return_type)) {
			[$decl, $symbol] = $this->factory->create_virtual_function('__php_unknow_func');
		}
		else {
			throw $this->new_syntax_error("Invalid callable expression", $node);
		}

		return $decl;
	}

	private function get_callable_declaration_from_type(BaseType $type): ?ICallableDeclaration
	{
		$type = TypeHelper::unwrap_excludable_type($type);
		if ($type instanceof ICallableDeclaration) {
			return $type;
		}

		if (!$type instanceof UnionType) {
			return null;
		}

		$callable = null;
		foreach ($type->get_members() as $member) {
			if ($member instanceof NoneType) {
				continue;
			}

			if ($member instanceof ICallableDeclaration) {
				$callable ??= $member;
				continue;
			}

			return null;
		}

		return $callable;
	}

	private function check_callable_declaration(ICallableDeclaration $node)
	{
		if ($this->is_checking($node)) {
			throw $this->new_syntax_error("Function '{$node->name}' has a circular checking, needs a return type", $node);
		}

		$this->mark_checking($node);

		switch ($node::KIND) {
			case MethodDeclaration::KIND:
			case MemberMappingDeclaration::KIND:
				$this->check_method_declaration($node);
				break;

			case FunctionDeclaration::KIND:
				$this->check_function_declaration($node);
				break;

			case AnonymousFunction::KIND:
				$this->infer_anonymous_function($node);
				break;

			default:
				$kind = $node::KIND;
				throw $this->new_syntax_error("Unexpect callable declaration kind: '{$kind}'", $node);
		}
	}

	private function should_check_callable_declaration(ICallableDeclaration $node): bool
	{
		return $node instanceof MethodDeclaration
			|| $node instanceof MemberMappingDeclaration
			|| $node instanceof FunctionDeclaration
			|| $node instanceof AnonymousFunction;
	}

	private function require_accessing_identifier_declaration(BaseType $basing_type, AccessingIdentifier $node): IDeclaration
	{
		if ($basing_type instanceof InvalidableType && $basing_type->sentinel instanceof LiteralNone) {
			if (!$this->is_weakly_checking && !$node->nullsafe) {
				$type_name = $this->get_type_name($basing_type);
				throw $this->new_syntax_error("Cannot accessing '$node->name' to '$type_name'", $node);
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
					if ($this->is_weakly_checking || $node->nullsafe) {
						continue;
					}

					$type_name = $this->get_type_name($basing_type);
					throw $this->new_syntax_error("Cannot accessing '$node->name' to '$type_name'", $node);
				}

				$resolved_decl ??= $this->require_accessing_identifier_declaration_for_type($member_type, $node);
			}

			if ($resolved_decl !== null) {
				return $resolved_decl;
			}

			$type_name = $this->get_type_name($basing_type);
			throw $this->new_syntax_error("Cannot accessing '$node->name' to '$type_name'", $node);
		}

		// if ($basing_type === TypeFactory::$_any) {
		// 	// let member type to Any on master is Any
		// 	$this->create_any_symbol_for_accessing_identifier($node);
		// }
		// elseif ($basing_type === TypeFactory::$_namespace) {
		// 	$this->attach_namespace_member_symbol(...);
		// }
		if ($basing_type instanceof MetaType) {
			$this->attach_symbol_for_metatype_accessing_identifier($node, $basing_type);
		}
		elseif ($basing_type instanceof BaseType) {
			$this->attach_symbol_for_basetype_accessing_identifier($node, $basing_type);
		}
		elseif ($basing_type instanceof Identifiable) {
			$basing_decl = $this->get_actual_class_declaration_for_metatype_expr($basing_type);
			$this->set_symbol_for_symbolic_node($node, $this->require_class_member_symbol($basing_decl, $node));
			if (!$this->is_instance_accessable($node->basing, $this->get_symbol_for_symbolic_node($node)->declaration)) {
				throw $this->new_syntax_error("Cannot access private/protected members", $node);
			}
		}
		else {
			$type_name = $this->get_type_name($basing_type);
			throw $this->new_syntax_error("Invalid accessable type '$type_name'", $node->basing);
		}

		$decl = $this->get_symbol_for_symbolic_node($node)->declaration;
		return $decl;
	}

	private function get_actual_class_declaration_for_metatype_expr(Identifiable $identifier): ClassKindredDeclaration
	{
		// the master would be an object expression, or variable of MetaType
		$decl = $this->get_symbol_for_symbolic_node($identifier)->declaration;
		if ($decl instanceof IVariableDeclaration) {
			$generic_type = TypeHelper::get_bound_type($decl)->generic_type;
			$decl = TypeHelper::get_type_symbol($generic_type)->declaration;
		}

		return $decl;
	}

	private function attach_symbol_for_basetype_accessing_identifier(AccessingIdentifier $node, BaseType $basing_type)
	{
		$basing_symbol = $this->get_symbol_for_symbolic_node($basing_type);
		if ($basing_symbol === null && $basing_type instanceof TypeReference) {
			$basing_symbol = $this->find_type_symbol_and_check_declaration($basing_type);
			$this->set_symbol_for_symbolic_node($basing_type, $basing_symbol);
		}
		elseif ($basing_symbol === null && $this->allow_weak_virtual_basing_class($basing_type, $node)) {
			[$decl, $basing_symbol] = $this->factory->create_virtual_class($basing_type->name, $this->program);
			$this->set_symbol_for_symbolic_node($basing_type, $basing_symbol);
		}
		if ($basing_symbol === null) {
			$type_name = $this->get_type_name($basing_type);
			throw $this->new_syntax_error("Cannot accessing '{$node->name}' to '{$type_name}'", $node);
		}

		$basing_decl = $basing_symbol->declaration;
		if (!$basing_decl instanceof ClassKindredDeclaration) {
			$basing_decl = $this->filter_classkindred_declaration($basing_decl, $basing_type, $node);
		}
		// $symbol = $this->find_member_symbol_in_class_declaration($basing_decl, $node->name);
		// if ($symbol === null) {
		// 	if ($basing_decl->is_virtual || $this->is_type_as_dynamic_class($basing_type)) {
		// 		[$decl, $symbol] = $this->factory->create_virtual_property($node->name, $basing_decl);
		// 	}
		// 	else {
		// 		throw $this->new_syntax_error("Member '{$node->name}' not found in '{$basing_decl->name}'", $node);
		// 	}
		// }

		$allow_weak_virtual_member = $this->allow_weak_virtual_member_for_type($basing_type, $basing_decl, $node);
		$symbol = $this->require_class_member_symbol($basing_decl, $node, $allow_weak_virtual_member);
		$this->set_symbol_for_symbolic_node($node, $symbol);
	}

	private function is_type_as_dynamic_class(BaseType $type)
	{
		return $this->is_weakly_checking && ($type instanceof AnyType || $type instanceof MixedType || $type instanceof StringType);
	}

	private function allow_weak_virtual_basing_class(BaseType $type, AccessingIdentifier $node): bool
	{
		return $type instanceof AnyType
			|| $type instanceof MixedType
			|| ($this->is_weakly_checking
				&& ($type instanceof ObjectType || ($type instanceof StringType && $node->is_static)));
	}

	private function allow_weak_virtual_member_for_type(BaseType $type, ClassKindredDeclaration $class_decl, AccessingIdentifier $node): bool
	{
		if ($type instanceof AnyType || $type instanceof MixedType) {
			return true;
		}

		if (!$this->is_weakly_checking) {
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

	private function attach_symbol_for_metatype_accessing_identifier(AccessingIdentifier $node, MetaType $basing_type) {
		$basing_decl = TypeHelper::get_type_symbol($basing_type->generic_type)->declaration;
		$symbol = $this->require_class_member_symbol($basing_decl, $node);

		// check static member

		$this->set_symbol_for_symbolic_node($node, $symbol);
		$node_declaration = $symbol->declaration;
		if ($node_declaration instanceof ClassConstantDeclaration) {
			$node_declaration->is_static = true;
		}
		if (!$node_declaration->is_static) {
			throw $this->new_syntax_error("Invalid to accessing a non-static member", $node);
		}

		if (!$this->is_static_accessable($node->basing, $node_declaration)) {
			throw $this->new_syntax_error("Cannot accessing the private/protected members", $node);
		}
	}

	private function is_instance_accessable(BaseExpression $expr, IClassMemberDeclaration $member) {
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

	private function is_static_accessable(BaseExpression $expr, IClassMemberDeclaration $member) {
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

	private function find_member_symbol_in_class_declaration(ClassKindredDeclaration $classkindred, string $name): ?Symbol
	{
		if (!$this->is_classkindred_ready($classkindred)) {
			$this->preprocess_classkindred_declaration($classkindred);
		}

		// when is super member
		$symbol = ASTHelper::get_aggregated_members($classkindred)[$name] ?? null;
		if ($symbol) {
			$decl = $symbol->declaration;
			if (!$this->is_checked($decl)) {
				// switch to target program
				$temp_program = $this->program;
				$actual_class = $decl->belong_block;
				$this->set_program($actual_class->program, __LINE__);

				$this->check_class_member_declaration($decl);

				// switch back
				$this->set_program($temp_program, __LINE__);
			}
		}

		return $symbol;
	}

	private function find_property_symbol_in_class_declaration(ClassKindredDeclaration $classkindred, string $name): ?Symbol
	{
		if (!$this->is_classkindred_ready($classkindred)) {
			$this->preprocess_classkindred_declaration($classkindred);
		}

		$property_key = ClassKindredDeclaration::get_property_symbol_key($name);
		if (isset(ASTHelper::get_aggregated_members($classkindred)[$property_key])) {
			return $this->find_member_symbol_in_class_declaration($classkindred, $property_key);
		}

		return $this->find_member_symbol_in_class_declaration($classkindred, $name);
	}

	private function require_class_member_symbol(ClassKindredDeclaration $class_decl, AccessingIdentifier $node, bool $allow_weak_virtual_member = true): Symbol
	{
		$name = $node->name;
		if ($node->is_static && $name === _CLASS) {
			[$decl, $symbol] = $this->factory->create_virtual_class_constant($name, TypeFactory::$_plain, $class_decl);
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
					[$decl, $symbol] = $this->factory->create_virtual_method($name, $class_decl);
				}
				else {
					[$decl, $symbol] = $this->factory->create_virtual_property($name, $class_decl);
				}
			}
			if ($symbol === null && $this->is_weakly_checking && !$allow_weak_virtual_member) {
				throw $this->new_syntax_error("Cannot accessing '{$name}' to '{$class_decl->name}'", $node);
			}
			if ($symbol === null) {
				throw $this->new_syntax_error("Member '{$name}' not found in '{$class_decl->name}'", $node);
			}

			$symbol->declaration->is_static = $node->is_static;
		}

		return $symbol;
	}

	private function try_create_runtime_member_symbol(ClassKindredDeclaration $class_decl, AccessingIdentifier $node): ?Symbol
	{
		if (!$this->is_weakly_checking) {
			return null;
		}

		$runtime_class = $this->get_runtime_class_name($class_decl);
		if ($runtime_class === null) {
			return null;
		}

		if (method_exists($runtime_class, $node->name)) {
			[$decl, $symbol] = $this->factory->create_virtual_method($node->name, $class_decl);
			try {
				$decl->is_static = (new \ReflectionMethod($runtime_class, $node->name))->isStatic();
			}
			catch (\ReflectionException $e) {
				// Keep the virtual method metadata minimal when reflection cannot inspect it.
			}

			return $symbol;
		}

		if (!$node->is_invoking() && property_exists($runtime_class, $node->name)) {
			[$decl, $symbol] = $this->factory->create_virtual_property($node->name, $class_decl);
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
			$can = $decl instanceof TraitDeclaration || in_array($decl->name, PHPChecker::DYNAMIC_CLASS_NAMES);
		}
		else {
			$can = false;
		}

		return $can;
	}

	private function get_class_declaration_for_expr(BaseExpression $expr)
	{
		$type = $this->infer_expression($expr);

		if ($type instanceof UnionType) {
			$type_name = $this->get_type_name($type);
			throw $this->new_syntax_error("Cannot create instance for '$type_name'", $expr);
		}

		$symbol = TypeHelper::get_type_symbol($type);
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

	private function get_unchecked_classkindred_declaration(PlainIdentifier|TypeReference $identifier)
	{
		$symbol = $this->get_symbol_for_symbolic_node($identifier);
		if ($symbol === null) {
			if (!$this->is_weakly_checking) {
				throw $this->new_syntax_error("Symbol of identifier '{$identifier->name}' not linked", $identifier);
			}

			$symbol = $this->find_symbol_for_plain_identifier($identifier)
				?? $this->try_create_virtual_symbol($identifier);
			$this->set_symbol_for_symbolic_node($identifier, $symbol);
		}

		$decl = $symbol->declaration;
		if (!$decl instanceof ClassKindredDeclaration) {
			throw $this->new_syntax_error("Declaration of identifier '{$identifier->name}' not classkindred", $identifier);
		}

		$this->is_classkindred_ready($decl) || $this->preprocess_classkindred_declaration($decl);
		return $decl;
	}

	private function get_checked_classkindred_declaration(PlainIdentifier|TypeReference $identifier, bool $required = false): ClassKindredDeclaration
	{
		$symbol = $this->get_symbol_for_symbolic_node($identifier);
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

	private function filter_classkindred_declaration(BaseDeclaration $decl, BaseType $type, BaseExpression|TypeReference $expr): ClassKindredDeclaration
	{
		if ($decl instanceof IVariableDeclaration && $type instanceof MetaType) {
			$decl = TypeHelper::get_type_symbol($type->generic_type)->declaration;
		}

		if ($decl instanceof ClassKindredDeclaration) {
			if (!$this->is_checked($decl)) {
				$temp_program = $this->program;
				$decl->program and $this->set_program($decl->program, __LINE__);
				$this->check_classkindred_declaration($decl);
				$decl->program and $this->set_program($temp_program, __LINE__);
			}
		}
		else {
			$message = $expr instanceof PlainIdentifier
				? "Type of expression not classkindred"
				: "Declaration of '{$decl->name}' not classkindred";
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
			$symbol = ASTHelper::get_scope_symbol($unit, $name);
			if ($symbol === null) {
				$target_declaration = $this->find_namespace_declaration_for_use($use, $unit);
				if ($target_declaration === null) {
					throw $this->new_syntax_error("Target '{$name}' for use not found in package '{$unit->name}'", $use);
				}
			}
			else {
				$target_declaration = $symbol->declaration;
			}
		}
		else {
			// the use namespace self mode
			$target_declaration = $unit;
		}

		if ($use->import_kind !== null) {
			$this->check_use_import_kind($use, $target_declaration);
		}

		ASTHelper::set_use_source_declaration($use, $target_declaration);
		$this->mark_checked($use);
	}

	private function find_namespace_declaration_for_use(UseDeclaration $use, Unit $unit): ?NamespaceDeclaration
	{
		$name = $use->source_name ?? $use->target_name;
		if ($name === null) {
			return null;
		}

		$namespaces = [];
		if (!$use->ns->is_global_space()) {
			$namespaces[] = new NamespaceIdentifier(array_merge($use->ns->get_namepath(), [$name]));
		}

		$namespaces[] = new NamespaceIdentifier([$name]);

		foreach ($namespaces as $ns) {
			$decl = $this->find_namespace_declaration_in_unit($unit, $ns);
			if ($decl !== null) {
				return $decl;
			}
		}

		return null;
	}

	private function check_use_import_kind(UseDeclaration $use, Unit|BaseDeclaration $target_declaration): void
	{
		$is_matched = false;
		if ($use->import_kind === UseDeclaration::IMPORT_CLASS) {
			$is_matched = $target_declaration instanceof ClassKindredDeclaration
				|| $target_declaration instanceof NamespaceDeclaration;
		}
		elseif ($use->import_kind === UseDeclaration::IMPORT_FUNCTION) {
			$is_matched = $target_declaration instanceof FunctionDeclaration;
		}
		elseif ($use->import_kind === UseDeclaration::IMPORT_CONST) {
			$is_matched = $target_declaration instanceof ConstantDeclaration;
		}

		if ($is_matched) {
			return;
		}

		$name = $use->source_name ?? $use->target_name ?? $use->ns->uri;
		$actual_kind = $this->describe_use_import_declaration($target_declaration);
		throw $this->new_syntax_error("Target '{$name}' for use expects {$use->import_kind}, {$actual_kind} given", $use);
	}

	private function describe_use_import_declaration(Unit|BaseDeclaration $target_declaration): string
	{
		if ($target_declaration instanceof Unit) {
			return 'namespace';
		}
		elseif ($target_declaration instanceof ClassKindredDeclaration) {
			return 'class';
		}
		elseif ($target_declaration instanceof FunctionDeclaration) {
			return 'function';
		}
		elseif ($target_declaration instanceof ConstantDeclaration) {
			return 'const';
		}

		return 'declaration';
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

	private function reduce_types(array $types): ?BaseType
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

		if ($result_type !== TypeFactory::$_any && $result_type !== TypeFactory::$_mixed) {
			for ($i = $i + 1; $i < $count; $i++) {
				$type = $types[$i];
				if ($type === null || $type === TypeFactory::$_none) {
					$nullable = true;
				}
				elseif ($type === TypeFactory::$_mixed) {
					$result_type = $type;
					break;
				}
				elseif ($type === TypeFactory::$_any) {
					$result_type = $type;
					break;
				}
				else {
					$result_type = $result_type->unite($type);
				}
			}

		}

		if ($nullable && $result_type) {
			$result_type = TypeHelper::to_nullable($result_type);
		}

		return $result_type;
	}

	public function new_syntax_error(string $message, Node $node)
	{
		if ($node->pos) {
			$program = $this->get_error_program_for_node($node);
			$place = $program->parser->get_error_place_with_pos($node->pos);
			$place = self::format_error_place($program, $place);
		}
		else {
			$place = get_class($node);
			$node_name = self::get_node_name_for_error($node);
			if ($node_name !== null) {
				$place .= " of '$node_name'";
			}

			$program = $this->get_error_program_for_node($node);
			$place = "Near $place on check {$program->name}";
			$unit = $program->unit;
			if ($unit !== null && $unit->name !== null) {
				$place = "{$unit->name}::{$place}";
			}
		}

		$message = "Syntax check error: {$message}\n-----\n{$place}\n-----";
		// defined('DEBUG') && DEBUG && $message .= "\n\nTraces:\n" . get_traces();

		return new Exception($message);
	}

	public function consume_check_warnings(): array
	{
		$warnings = $this->check_warnings;
		$this->check_warnings = [];
		return $warnings;
	}

	private function add_syntax_warning(string $message, Node $node): void
	{
		$warning = $this->new_syntax_error($message, $node)->getMessage();
		$this->check_warnings[] = preg_replace('/^Syntax check error:/', 'Syntax check warning:', $warning);
	}

	private function get_error_program_for_node(Node $node): Program
	{
		if ($node instanceof BaseDeclaration) {
			if ($node->program !== null) {
				return $node->program;
			}

			$block = $node->belong_block;
			while ($block !== null) {
				if ($block instanceof BaseDeclaration && $block->program !== null) {
					return $block->program;
				}

				$block = $block instanceof IBlock ? $block->get_belong_block() : null;
			}
		}

		return $this->program;
	}

	private static function format_error_place(Program $program, string $place): string
	{
		$unit = $program->unit;
		if ($unit === null) {
			return $place;
		}

		$unit_path = FileHelper::normalize_path($unit->path);
		$place = FileHelper::normalize_path($place);
		if (str_starts_with($place, $unit_path)) {
			$package_dir = basename(rtrim($unit_path, DS));
			$place = $package_dir . DS . substr($place, strlen($unit_path));
		}

		return $unit->name === null ? $place : "{$unit->name}::{$place}";
	}

	private static function get_node_name_for_error(Node $node): ?string
	{
		if ($node instanceof BaseDeclaration || $node instanceof Identifiable) {
			return $node->name;
		}

		return null;
	}

	static function get_declaration_name(BaseDeclaration $decl)
	{
		if (isset($decl->belong_block) && $decl->belong_block instanceof ClassDeclaration) {
			return "{$decl->belong_block->name}.{$decl->name}";
		}

		return $decl->name;
	}

	static function get_type_name(BaseType $type)
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
				$args[] = static::get_type_name($param->get_expressed_type());
			}

			$name = '(' . join(', ', $args) . ') ' . static::get_type_name($type->get_expressed_type());
		}
		elseif ($type instanceof UnionType) {
			$names = [];
			foreach ($type->get_members() as $member) {
				$expr = static::get_type_name($member);
				if ($member instanceof CallableType) {
					$expr = "($expr)";
				}

				$names[] = $expr;
			}

			$name = join('|', $names);
		}
		else {
			$name = $type ? static::get_type_reference_name($type) : '-';
		}

		// if ($type->nullable) {
		// 	$name .= '?';
		// }

		return $name;
	}

	static function get_type_reference_name(BaseType $identifier)
	{
		$name = $identifier->name;
		if ($identifier instanceof TypeReference and $identifier->ns) {
			$names = $identifier->ns->names;
			$names[] = $name;
			$name = join(static::NS_SEPARATOR, $names);
		}

		return $name;
	}
}

// end
