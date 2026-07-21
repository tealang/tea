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

	private static array $type_assertions = [];
	private CheckerServices $checker_services;

	private static $builtin_checker_instance;
	private static $native_checker_instance;
	private static $normal_checker_instances = [];

	public static function reset_check_state(): void
	{
		CheckerContext::reset_check_state();
		self::$type_assertions = [];
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
		$this->checker_services = CheckerServiceGraph::build(
			$this->is_weakly_checking,
			$unit,
			$builtin_unit,
			$this->create_checker_callbacks()
		);
	}

	private function create_checker_callbacks(): CheckerCallbacks
	{
		return new CheckerCallbacks(
			function (ClassKindredDeclaration $decl): void {
				$this->preprocess_classkindred_declaration($decl);
			},
			function (ClassKindredDeclaration $decl): void {
				$this->check_classkindred_declaration($decl);
			},
			fn(BaseExpression $expr): BaseType => $this->infer_expression($expr),
			fn(IDeclaration|AnonymousFunction|CallableType $node): ?BaseType => $this->get_and_check_hinted_type($node),
			function (IDeclaration $node): void {
				$this->checker_services->compile_time_value_checker->assert_compile_time_value_for($node);
			},
			function (BaseType $left, BaseType $right, Node $value_node): void {
				$this->checker_services->type_compatibility_reporter->assert_type_compatible($left, $right, $value_node);
			},
			fn(): BaseType => $this->get_unknown_php_value_type(),
			function (FunctionDeclaration $decl): void {
				$this->checker_services->callable_declaration_resolver->check_function_declaration($decl);
			},
			fn(array $types, ?IBlock $block = null): ?BaseType => $this->reduce_types($types, $block),
			fn(IElseBlock $block): ?BaseType => $this->infer_else_block($block),
			fn(IBlock $block): ?BaseType => $this->infer_block($block),
			fn(BaseExpression $expr): array => $this->get_type_assertion($expr),
			fn(BaseExpression $expr): array => $this->get_empty_condition_target($expr),
			fn(PlainIdentifier $identifier): BaseType => $this->infer_plain_identifier($identifier),
			fn(AccessingIdentifier $identifier, bool $ignore_none = false): BaseType => $this->infer_accessing_identifier($identifier, $ignore_none),
			fn(BaseExpression $expr): ?IsOperation => $this->get_type_assertion_for($expr),
			function (BaseExpression $expr, ?IsOperation $assertion): void {
				$this->set_type_assertion_for($expr, $assertion);
			}
		);
	}

	public function link_declarations(Program $program)
	{
		$this->checker_services->declaration_linker->link_declarations($program);
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
		$this->checker_services->checker_context->set_program($program);
	}

	private function get_program(): Program
	{
		return $this->checker_services->checker_context->get_program();
	}

	public function check_program(Program $program)
	{
		if ($this->is_checked($program)) return;
		$this->mark_checked($program);

		$this->set_program($program, __LINE__);

		foreach ($program->declarations as $node) {
			$this->checker_services->declaration_checker->check_declaration($node);
		}

		if ($program->initializer) {
			$this->checker_services->declaration_checker->check_declaration($program->initializer);
		}
	}

	private function is_checked(object $node): bool
	{
		return $this->checker_services->checker_context->is_checked($node);
	}

	private function mark_checked(object $node): void
	{
		$this->checker_services->checker_context->mark_checked($node);
	}

	private function is_classkindred_ready(ClassKindredDeclaration $node): bool
	{
		return $this->checker_services->checker_context->is_classkindred_ready($node);
	}

	private function mark_classkindred_ready(ClassKindredDeclaration $node): void
	{
		$this->checker_services->checker_context->mark_classkindred_ready($node);
	}

	private function is_checking(object $node): bool
	{
		return $this->checker_services->checker_context->is_checking($node);
	}

	private function mark_checking(object $node): void
	{
		$this->checker_services->checker_context->mark_checking($node);
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
			$this->checker_services->use_resolver->resolve_use_target($target);
		}
	}

	private function check_class_member_declaration(IClassMemberDeclaration $node)
	{
		$this->checker_services->class_body_resolver->check_class_member_declaration($node);
	}

	private function get_and_check_hinted_type(IDeclaration|AnonymousFunction|CallableType $node)
	{
		return $this->checker_services->type_reference_checker->get_and_check_hinted_type($node);
	}

	private function check_function_kindred(IFunctionDeclaration $node)
	{
		$this->checker_services->function_body_resolver->check_function_kindred($node);
	}

	private function infer_function_body(IFunctionDeclaration $node, ?BaseType $hinted)
	{
		return $this->checker_services->function_body_resolver->infer_function_body($node, $hinted);
	}

	protected function check_method_declaration(MethodDeclaration $node)
	{
		$this->checker_services->class_body_resolver->check_method_declaration($node);
	}

	private function preprocess_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$this->checker_services->class_body_resolver->preprocess_classkindred_declaration($node);
	}

	private function check_classkindred_declaration(ClassKindredDeclaration $node)
	{
		$this->checker_services->class_body_resolver->check_classkindred_declaration($node);
	}

	// private function check_generic_types(PlainIdentifier $identifier) {
	// 	foreach ($identifier->generic_types as $key => $type) {
	// 		$this->check_type($type);
	// 	}
	// }

	private function get_type_assertion(BaseExpression $expr)
	{
		return $this->checker_services->type_assertion_resolver->resolve_condition_assertion($expr);
	}

	private function get_empty_condition_target(BaseExpression $expr): array
	{
		return $this->checker_services->type_assertion_resolver->resolve_empty_condition_target($expr);
	}

	protected function infer_else_block(IElseBlock $node): ?BaseType
	{
		return $this->checker_services->block_statement_resolver->infer_else_block($node);
	}

	private function infer_single_expression_block(IBlock $block): BaseType
	{
		return $this->checker_services->block_statement_resolver->infer_single_expression_block($block);
	}

	private function infer_block(IBlock $block): ?BaseType
	{
		return $this->checker_services->block_statement_resolver->infer_block($block);
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
		return $this->checker_services->block_statement_resolver->infer_statement($node);
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
					? TypeFactory::$_text
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
				$infered = $this->checker_services->markup_literal_resolver->infer_plain_interpolated_string($node);
				break;
			case EscapedInterpolatedString::KIND:
				$infered = $this->checker_services->markup_literal_resolver->infer_escaped_interpolated_string($node);
				break;
			case XTag::KIND:
				$infered = $this->checker_services->markup_literal_resolver->infer_xtag($node);
				break;

			// -------
			case AccessingIdentifier::KIND:
				$infered = $this->infer_accessing_identifier($node);
				break;
			case KeyAccessing::KIND:
				$infered = $this->checker_services->key_access_resolver->infer_key_accessing($node);
				break;
			case SquareAccessing::KIND:
				$infered = $this->checker_services->key_access_resolver->infer_square_accessing($node);
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
				$infered = $this->checker_services->expression_leaf_resolver->infer_first_class_callable_expression($node);
				break;
			case PipeCallExpression::KIND:
				$infered = $this->infer_pipecall_expression($node);
				break;

			case AssignmentOperation::KIND:
				$infered = $this->checker_services->assignment_resolver->infer_assignment_operation($node);
				break;
			case BinaryOperation::KIND:
				$infered = $this->checker_services->operation_resolver->infer_binary_operation($node);
				break;
			case AsOperation::KIND:
				$infered = $this->checker_services->operation_resolver->infer_as_operation($node);
				break;
			case CastOperation::KIND:
				$infered = $this->checker_services->operation_resolver->infer_cast_operation($node);
				break;
			case IsOperation::KIND:
				$infered = $this->checker_services->operation_resolver->infer_is_operation($node);
				break;
			case PrefixOperation::KIND:
				$infered = $this->checker_services->operation_resolver->infer_prefix_operation($node);
				break;
			case PostfixOperation::KIND:
				$infered = $this->checker_services->operation_resolver->infer_postfix_operation($node);
				break;

			case Parentheses::KIND:
				$infered = $this->infer_expression($node->expression);
				break;
			// case StringInterpolation::KIND:
				// 	$infered = $this->infer_expression($node->expression);
				// 	break;
			case NoneCoalescingOperation::KIND:
				$infered = $this->checker_services->operation_resolver->infer_none_coalescing_expression($node);
				break;
			case TernaryExpression::KIND:
				$infered = $this->checker_services->operation_resolver->infer_ternary_expression($node);
				break;
			case DictExpression::KIND:
				$infered = $this->checker_services->container_literal_resolver->infer_dict_expression($node);
				break;
			case ObjectExpression::KIND:
				$infered = $this->checker_services->object_expression_resolver->infer_object_expression($node);
				break;
			case ArrayExpression::KIND:
				$infered = $this->checker_services->container_literal_resolver->infer_array_expression($node);
				break;
			case AnonymousFunction::KIND:
				$infered = $this->checker_services->anonymous_function_resolver->infer_anonymous_function($node);
				break;
			// case NamespaceIdentifier::KIND:
			// 	$infered = $this->infer_namespace_identifier($node);
			//	break;
			case IncludeExpression::KIND:
				$infered = $this->checker_services->expression_leaf_resolver->infer_include_expression($node);
				break;
			case YieldExpression::KIND:
				$infered = $this->checker_services->expression_leaf_resolver->infer_yield_expression($node);
				break;
			case ThrowExpression::KIND:
				$infered = $this->checker_services->expression_leaf_resolver->infer_throw_expression($node);
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
				$infered = $this->checker_services->switch_match_resolver->infer_match_block($node);
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

	private function infer_constant_identifier(ConstantIdentifier $node): BaseType
	{
		$decl = $this->checker_services->symbol_declaration_resolver->get_actual_declaration_for_identifier($node);
		return $decl->get_expressed_type();
	}

	private function infer_new_expression(InstancingExpression $node): BaseType
	{
		$callee = $node->callee;

		// the endmost declaration, some times maybe not the direct
		if ($callee instanceof PlainIdentifier) {
			$decl = $this->checker_services->classkindred_lookup_resolver->get_checked_classkindred_declaration($callee);
		}
		else {
			$decl = $this->get_class_declaration_for_expr($callee);
		}

		$infered = $this->checker_services->instancing_resolver->infer_instancing_expr($callee, $decl);

		$this->checker_services->call_resolver->check_call_arguments($node, $decl);

		return $infered;
	}

	private function infer_call_expression(CallExpression $node): BaseType
	{
		$callee_name = $this->checker_services->type_assertion_resolver->get_plain_callee_name($node->callee);
		if ($this->is_weakly_checking && in_array($callee_name, ['empty', 'isset', 'unset'], true)) {
			return $this->infer_php_soft_key_access_call($node, $callee_name);
		}

		return $this->checker_services->call_resolver->infer_basecall_expression($node);
	}

	private function infer_php_soft_key_access_call(CallExpression $node, string $callee_name): BaseType
	{
		$allow_php_soft_key_access = $this->checker_services->checker_context->allows_php_soft_key_access();
		$this->checker_services->checker_context->set_php_soft_key_access(true);
		try {
			foreach ($node->arguments as $argument) {
				$this->infer_expression($argument);
			}
		}
		finally {
			$this->checker_services->checker_context->set_php_soft_key_access($allow_php_soft_key_access);
		}

		return $callee_name === 'unset' ? TypeFactory::$_void : TypeFactory::$_bool;
	}

	private function infer_pipecall_expression(PipeCallExpression $node): BaseType
	{
		return $this->checker_services->call_resolver->infer_basecall_expression($node);
	}

	protected function infer_plain_identifier(PlainIdentifier $node): BaseType
	{
		$decl = $this->checker_services->symbol_declaration_resolver->get_actual_declaration_for_identifier($node);

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
		return $this->checker_services->member_resolver->infer_accessing_identifier($node, $ignore_none);
	}

	private function infer_regular_expression(RegularExpression $node): BaseType
	{
		return TypeFactory::$_regex;
	}

	private function infer_variable_identifier(VariableIdentifier $node): BaseType
	{
		$decl = $this->checker_services->symbol_declaration_resolver->get_actual_declaration_for_identifier($node);
		return TypeHelper::get_bound_type($decl);
	}

	private function get_symbol_for_symbolic_node(Identifiable|BaseType $node): ?Symbol
	{
		return $this->checker_services->name_resolver->get_symbol_for_symbolic_node($node);
	}

	private function set_symbol_for_symbolic_node(Identifiable|BaseType $node, ?Symbol $symbol): void
	{
		$this->checker_services->name_resolver->set_symbol_for_symbolic_node($node, $symbol);
	}

	private function resolve_use_symbol_declaration(Symbol $symbol, Identifiable|TypeReference|null $identifier = null): ?BaseDeclaration
	{
		return $this->checker_services->use_resolver->resolve_use_symbol_declaration($symbol, $identifier);
	}

	private function find_symbol_for_type(BaseType $type)
	{
		return $this->checker_services->name_resolver->find_symbol_for_type($type);
	}

	private function find_symbol_for_plain_identifier(PlainIdentifier|TypeReference $identifier)
	{
		return $this->checker_services->name_resolver->find_symbol_for_plain_identifier($identifier);
	}

	private function find_symbol_by_current_namespace_prefix(NamespaceIdentifier $ns, string $name): ?Symbol
	{
		return $this->checker_services->name_resolver->find_symbol_by_current_namespace_prefix($ns, $name);
	}

	private function find_symbol_by_php_use_alias_prefix(NamespaceIdentifier $ns, string $name): ?Symbol
	{
		return $this->checker_services->name_resolver->find_symbol_by_php_use_alias_prefix($ns, $name);
	}

	private function find_symbol_in_current_namespace(PlainIdentifier|TypeReference $identifier): ?Symbol
	{
		return $this->checker_services->name_resolver->find_symbol_in_current_namespace($identifier);
	}

	private function check_namespace(NamespaceIdentifier $ns)
	{
		$this->checker_services->name_resolver->check_namespace($ns);
	}

	private function get_symbol_in_unit(Unit $unit, string $name)
	{
		return $this->checker_services->name_resolver->get_symbol_in_unit($unit, $name);
	}

	private function get_unit_by_uri(string $uri)
	{
		return $this->checker_services->name_resolver->get_unit_by_uri($uri);
	}

	private function find_symbol_in_namespace(NamespaceIdentifier $ns, string $name, Unit $unit)
	{
		return $this->checker_services->name_resolver->find_symbol_in_namespace($ns, $name, $unit);
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
		return $this->checker_services->name_resolver->find_namespace_declaration_in_unit($unit, $ns);
	}

	private function get_class_declaration_for_expr(BaseExpression $expr)
	{
		$type = $this->infer_expression($expr);
		return $this->checker_services->classkindred_lookup_resolver->get_class_declaration_for_type($type, $expr);
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

		if ($result_type instanceof UnionType) {
			$result_type = TypeFactory::create_union_type($result_type->get_members());
		}

		return $result_type;
	}

	public function new_syntax_error(string $message, Node $node)
	{
		return $this->checker_services->checker_context->new_syntax_error($message, $node);
	}

	public function consume_check_warnings(): array
	{
		return $this->checker_services->checker_context->consume_check_warnings();
	}

	private function add_syntax_warning(string $message, Node $node): void
	{
		$this->checker_services->checker_context->add_syntax_warning($message, $node);
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
