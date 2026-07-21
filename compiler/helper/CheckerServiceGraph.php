<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class CheckerCallbacks
{
	private $preprocess_classkindred_declaration;
	private $check_classkindred_declaration;
	private $infer_expression;
	private $get_and_check_hinted_type;
	private $assert_compile_time_value_for;
	private $assert_type_compatible;
	private $get_unknown_php_value_type;
	private $check_function_declaration;
	private $reduce_types;
	private $infer_else_block;
	private $infer_block;
	private $get_type_assertion;
	private $get_empty_condition_target;
	private $infer_plain_identifier;
	private $infer_accessing_identifier;
	private $get_type_assertion_for;
	private $set_type_assertion_for;

	public function __construct(
		callable $preprocess_classkindred_declaration,
		callable $check_classkindred_declaration,
		callable $infer_expression,
		callable $get_and_check_hinted_type,
		callable $assert_compile_time_value_for,
		callable $assert_type_compatible,
		callable $get_unknown_php_value_type,
		callable $check_function_declaration,
		callable $reduce_types,
		callable $infer_else_block,
		callable $infer_block,
		callable $get_type_assertion,
		callable $get_empty_condition_target,
		callable $infer_plain_identifier,
		callable $infer_accessing_identifier,
		callable $get_type_assertion_for,
		callable $set_type_assertion_for
	) {
		$this->preprocess_classkindred_declaration = $preprocess_classkindred_declaration;
		$this->check_classkindred_declaration = $check_classkindred_declaration;
		$this->infer_expression = $infer_expression;
		$this->get_and_check_hinted_type = $get_and_check_hinted_type;
		$this->assert_compile_time_value_for = $assert_compile_time_value_for;
		$this->assert_type_compatible = $assert_type_compatible;
		$this->get_unknown_php_value_type = $get_unknown_php_value_type;
		$this->check_function_declaration = $check_function_declaration;
		$this->reduce_types = $reduce_types;
		$this->infer_else_block = $infer_else_block;
		$this->infer_block = $infer_block;
		$this->get_type_assertion = $get_type_assertion;
		$this->get_empty_condition_target = $get_empty_condition_target;
		$this->infer_plain_identifier = $infer_plain_identifier;
		$this->infer_accessing_identifier = $infer_accessing_identifier;
		$this->get_type_assertion_for = $get_type_assertion_for;
		$this->set_type_assertion_for = $set_type_assertion_for;
	}

	public function preprocess_classkindred_declaration(ClassKindredDeclaration $decl): void
	{
		($this->preprocess_classkindred_declaration)($decl);
	}

	public function check_classkindred_declaration(ClassKindredDeclaration $decl): void
	{
		($this->check_classkindred_declaration)($decl);
	}

	public function infer_expression(BaseExpression $expr): BaseType
	{
		return ($this->infer_expression)($expr);
	}

	public function get_and_check_hinted_type(IDeclaration|AnonymousFunction|CallableType $node): ?BaseType
	{
		return ($this->get_and_check_hinted_type)($node);
	}

	public function assert_compile_time_value_for(IDeclaration $node): void
	{
		($this->assert_compile_time_value_for)($node);
	}

	public function assert_type_compatible(BaseType $left, BaseType $right, Node $value_node): void
	{
		($this->assert_type_compatible)($left, $right, $value_node);
	}

	public function get_unknown_php_value_type(): BaseType
	{
		return ($this->get_unknown_php_value_type)();
	}

	public function check_function_declaration(FunctionDeclaration $decl): void
	{
		($this->check_function_declaration)($decl);
	}

	public function reduce_types(array $types, ?IBlock $block = null): ?BaseType
	{
		return ($this->reduce_types)($types, $block);
	}

	public function infer_else_block(IElseBlock $block): ?BaseType
	{
		return ($this->infer_else_block)($block);
	}

	public function infer_block(IBlock $block): ?BaseType
	{
		return ($this->infer_block)($block);
	}

	public function get_type_assertion(BaseExpression $expr): array
	{
		return ($this->get_type_assertion)($expr);
	}

	public function get_empty_condition_target(BaseExpression $expr): array
	{
		return ($this->get_empty_condition_target)($expr);
	}

	public function infer_plain_identifier(PlainIdentifier $identifier): BaseType
	{
		return ($this->infer_plain_identifier)($identifier);
	}

	public function infer_accessing_identifier(AccessingIdentifier $identifier, bool $ignore_none = false): BaseType
	{
		return ($this->infer_accessing_identifier)($identifier, $ignore_none);
	}

	public function get_type_assertion_for(BaseExpression $expr): ?IsOperation
	{
		return ($this->get_type_assertion_for)($expr);
	}

	public function set_type_assertion_for(BaseExpression $expr, ?IsOperation $assertion): void
	{
		($this->set_type_assertion_for)($expr, $assertion);
	}
}

class CheckerServices
{
	public CheckerContext $checker_context;
	public NameResolver $name_resolver;
	public VirtualSymbolFactory $virtual_symbol_factory;
	public ClassKindredLookupResolver $classkindred_lookup_resolver;
	public DeclarationLinker $declaration_linker;
	public ValueTrustResolver $value_trust_resolver;
	public UseResolver $use_resolver;
	public DeclarationChecker $declaration_checker;
	public DeclarationCheckTrigger $declaration_check_trigger;
	public SymbolDeclarationResolver $symbol_declaration_resolver;
	public OverrideResolver $override_resolver;
	public ClassPreprocessor $class_preprocessor;
	public ContainerLiteralResolver $container_literal_resolver;
	public MarkupLiteralResolver $markup_literal_resolver;
	public TypeCompatibilityReporter $type_compatibility_reporter;
	public CompileTimeValueChecker $compile_time_value_checker;
	public TypeReferenceChecker $type_reference_checker;
	public SimpleStatementResolver $simple_statement_resolver;
	public GotoResolver $goto_resolver;
	public ElseBlockTypeReducer $else_block_type_reducer;
	public IterationResolver $iteration_resolver;
	public FlowNarrowingResolver $flow_narrowing_resolver;
	public SwitchMatchResolver $switch_match_resolver;
	public LoopBlockResolver $loop_block_resolver;
	public ExceptionBlockResolver $exception_block_resolver;
	public BlockStatementResolver $block_statement_resolver;
	public FunctionBodyResolver $function_body_resolver;
	public AnonymousFunctionResolver $anonymous_function_resolver;
	public ClassBodyResolver $class_body_resolver;
	public ObjectExpressionResolver $object_expression_resolver;
	public KeyAccessResolver $key_access_resolver;
	public ExpressionLeafResolver $expression_leaf_resolver;
	public AssignmentResolver $assignment_resolver;
	public CallableDeclarationResolver $callable_declaration_resolver;
	public MemberResolver $member_resolver;
	public InstancingResolver $instancing_resolver;
	public CallResolver $call_resolver;
	public TypeAssertionResolver $type_assertion_resolver;
	public OperationResolver $operation_resolver;
}

class CheckerServiceGraph
{
	public static function build(bool $is_weakly_checking, Unit $unit, ?Unit $builtin_unit, CheckerCallbacks $callbacks): CheckerServices
	{
		$services = new CheckerServices();

		$services->checker_context = new CheckerContext($is_weakly_checking, $unit, $builtin_unit);
		$services->name_resolver = new NameResolver($services->checker_context);
		$services->virtual_symbol_factory = new VirtualSymbolFactory($services->checker_context);
		$services->classkindred_lookup_resolver = new ClassKindredLookupResolver(
			$services->checker_context,
			$services->name_resolver,
			$services->virtual_symbol_factory,
			function (ClassKindredDeclaration $decl) use ($callbacks): void {
				$callbacks->preprocess_classkindred_declaration($decl);
			},
			function (ClassKindredDeclaration $decl) use ($callbacks): void {
				$callbacks->check_classkindred_declaration($decl);
			}
		);
		$services->declaration_linker = new DeclarationLinker(
			$services->checker_context,
			$services->name_resolver,
			$services->virtual_symbol_factory
		);
		$services->value_trust_resolver = new ValueTrustResolver(
			$services->checker_context,
			$services->name_resolver
		);
		$services->use_resolver = new UseResolver(
			$services->checker_context,
			$services->name_resolver,
			$services->virtual_symbol_factory
		);
		$services->declaration_checker = new DeclarationChecker(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(IDeclaration|AnonymousFunction|CallableType $node): ?BaseType => $callbacks->get_and_check_hinted_type($node),
			function (IDeclaration $node) use ($callbacks): void {
				$callbacks->assert_compile_time_value_for($node);
			},
			function (BaseType $left, BaseType $right, Node $value_node) use ($callbacks): void {
				$callbacks->assert_type_compatible($left, $right, $value_node);
			},
			fn(): BaseType => $callbacks->get_unknown_php_value_type(),
			function (FunctionDeclaration $decl) use ($callbacks): void {
				$callbacks->check_function_declaration($decl);
			},
			function (ClassKindredDeclaration $decl) use ($callbacks): void {
				$callbacks->check_classkindred_declaration($decl);
			}
		);
		$services->declaration_check_trigger = new DeclarationCheckTrigger(
			$services->checker_context,
			$services->use_resolver,
			$services->declaration_checker
		);
		$services->symbol_declaration_resolver = new SymbolDeclarationResolver(
			$services->checker_context,
			$services->name_resolver,
			$services->use_resolver,
			$services->virtual_symbol_factory,
			$services->declaration_check_trigger
		);
		$services->override_resolver = new OverrideResolver($services->checker_context);
		$services->class_preprocessor = new ClassPreprocessor(
			$services->checker_context,
			$services->classkindred_lookup_resolver,
			$services->override_resolver
		);
		$services->container_literal_resolver = new ContainerLiteralResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(array $types): ?BaseType => $callbacks->reduce_types($types)
		);
		$services->markup_literal_resolver = new MarkupLiteralResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr)
		);
		$services->type_compatibility_reporter = new TypeCompatibilityReporter(
			$services->checker_context,
			$services->symbol_declaration_resolver
		);
		$services->compile_time_value_checker = new CompileTimeValueChecker(
			$services->checker_context,
			$services->name_resolver,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr)
		);
		$services->type_reference_checker = new TypeReferenceChecker(
			$services->checker_context,
			$services->name_resolver,
			$services->symbol_declaration_resolver,
			$services->declaration_check_trigger
		);
		$services->simple_statement_resolver = new SimpleStatementResolver(
			$services->checker_context,
			$services->type_compatibility_reporter,
			$services->type_reference_checker,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			$services->declaration_checker
		);
		$services->goto_resolver = new GotoResolver(
			$services->checker_context
		);
		$services->else_block_type_reducer = new ElseBlockTypeReducer(
			fn(IElseBlock $block): ?BaseType => $callbacks->infer_else_block($block),
			fn(array $types): ?BaseType => $callbacks->reduce_types($types)
		);
		$services->iteration_resolver = new IterationResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(IBlock $block): ?BaseType => $callbacks->infer_block($block),
			$services->else_block_type_reducer,
			fn(BaseExpression $node, array $types): BaseType => $services->simple_statement_resolver->expect_infered_type($node, $types)
		);
		$services->flow_narrowing_resolver = new FlowNarrowingResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(IBlock $block): ?BaseType => $callbacks->infer_block($block),
			$services->else_block_type_reducer,
			fn(BaseExpression $expr): array => $callbacks->get_type_assertion($expr),
			fn(BaseExpression $expr): array => $callbacks->get_empty_condition_target($expr)
		);
		$services->switch_match_resolver = new SwitchMatchResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(IBlock $block): ?BaseType => $callbacks->infer_block($block),
			fn(array $types): ?BaseType => $callbacks->reduce_types($types),
			$services->else_block_type_reducer
		);
		$services->loop_block_resolver = new LoopBlockResolver(
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(IBlock $block): ?BaseType => $callbacks->infer_block($block),
			$services->else_block_type_reducer
		);
		$services->exception_block_resolver = new ExceptionBlockResolver(
			fn(IBlock $block): ?BaseType => $callbacks->infer_block($block),
			$services->declaration_checker,
			fn(array $types): ?BaseType => $callbacks->reduce_types($types)
		);
		$services->block_statement_resolver = new BlockStatementResolver(
			$services->checker_context,
			$services->simple_statement_resolver,
			$services->goto_resolver,
			$services->flow_narrowing_resolver,
			$services->loop_block_resolver,
			$services->iteration_resolver,
			$services->exception_block_resolver,
			$services->switch_match_resolver,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(array $types, ?IBlock $block = null): ?BaseType => $callbacks->reduce_types($types, $block)
		);
		$services->function_body_resolver = new FunctionBodyResolver(
			$services->checker_context,
			$services->declaration_checker,
			$services->type_reference_checker,
			$services->goto_resolver,
			$services->type_compatibility_reporter,
			$services->block_statement_resolver
		);
		$services->anonymous_function_resolver = new AnonymousFunctionResolver(
			$services->checker_context,
			$services->name_resolver,
			$services->function_body_resolver,
			fn(PlainIdentifier $identifier): BaseType => $callbacks->infer_plain_identifier($identifier),
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr)
		);
		$services->class_body_resolver = new ClassBodyResolver(
			$services->checker_context,
			$services->class_preprocessor,
			$services->declaration_checker,
			$services->function_body_resolver,
			$services->type_reference_checker,
			$services->type_compatibility_reporter,
			$services->name_resolver,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(PlainIdentifier $identifier): BaseType => $callbacks->infer_plain_identifier($identifier)
		);
		$services->object_expression_resolver = new ObjectExpressionResolver(
			$services->class_body_resolver
		);
		$services->key_access_resolver = new KeyAccessResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(array $types): ?BaseType => $callbacks->reduce_types($types),
			$services->symbol_declaration_resolver,
			$services->use_resolver,
			$services->class_body_resolver,
			$services->container_literal_resolver
		);
		$services->expression_leaf_resolver = new ExpressionLeafResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			$services->key_access_resolver
		);
		$services->assignment_resolver = new AssignmentResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			function (AccessingIdentifier $identifier) use ($callbacks): BaseType {
				return $callbacks->infer_accessing_identifier($identifier);
			},
			$services->key_access_resolver,
			$services->type_compatibility_reporter,
			$services->container_literal_resolver
		);
		$services->callable_declaration_resolver = new CallableDeclarationResolver(
			$services->checker_context,
			$services->function_body_resolver,
			$services->class_body_resolver,
			$services->anonymous_function_resolver
		);
		$services->member_resolver = new MemberResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			$services->type_reference_checker,
			$services->callable_declaration_resolver,
			$services->class_body_resolver,
			$services->name_resolver,
			$services->symbol_declaration_resolver,
			$services->classkindred_lookup_resolver,
			$services->value_trust_resolver
		);
		$services->instancing_resolver = new InstancingResolver(
			$services->checker_context,
			$services->member_resolver
		);
		$services->call_resolver = new CallResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			$services->instancing_resolver,
			$services->callable_declaration_resolver,
			$services->type_compatibility_reporter,
			$services->assignment_resolver,
			$services->member_resolver,
			$services->symbol_declaration_resolver,
			$services->classkindred_lookup_resolver,
			$services->value_trust_resolver
		);
		$services->type_assertion_resolver = new TypeAssertionResolver(
			$services->checker_context,
			fn(BaseExpression $expr): ?IsOperation => $callbacks->get_type_assertion_for($expr),
			$services->flow_narrowing_resolver,
			fn(AccessingIdentifier $identifier): BaseType => $callbacks->infer_accessing_identifier($identifier)
		);
		$services->operation_resolver = new OperationResolver(
			$services->checker_context,
			fn(BaseExpression $expr): BaseType => $callbacks->infer_expression($expr),
			fn(AccessingIdentifier $identifier, bool $ignore_none = false): BaseType => $callbacks->infer_accessing_identifier($identifier, $ignore_none),
			fn(array $types): ?BaseType => $callbacks->reduce_types($types),
			fn(BaseExpression $expr): ?IsOperation => $callbacks->get_type_assertion_for($expr),
			function (BaseExpression $expr, ?IsOperation $assertion) use ($callbacks): void {
				$callbacks->set_type_assertion_for($expr, $assertion);
			},
			$services->type_reference_checker,
			$services->type_compatibility_reporter,
			$services->flow_narrowing_resolver,
			$services->member_resolver,
			$services->type_assertion_resolver
		);

		return $services;
	}
}

// end
