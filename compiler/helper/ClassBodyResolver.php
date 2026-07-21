<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ClassBodyResolver
{
	private CheckerContext $context;
	private ClassPreprocessor $class_preprocessor;
	private DeclarationChecker $declaration_checker;
	private FunctionBodyResolver $function_body_resolver;
	private TypeReferenceChecker $type_reference_checker;
	private TypeCompatibilityReporter $type_compatibility_reporter;
	private NameResolver $name_resolver;
	private $infer_expression;
	private $infer_plain_identifier;

	public function __construct(
		CheckerContext $context,
		ClassPreprocessor $class_preprocessor,
		DeclarationChecker $declaration_checker,
		FunctionBodyResolver $function_body_resolver,
		TypeReferenceChecker $type_reference_checker,
		TypeCompatibilityReporter $type_compatibility_reporter,
		NameResolver $name_resolver,
		callable $infer_expression,
		callable $infer_plain_identifier
	) {
		$this->context = $context;
		$this->class_preprocessor = $class_preprocessor;
		$this->declaration_checker = $declaration_checker;
		$this->function_body_resolver = $function_body_resolver;
		$this->type_reference_checker = $type_reference_checker;
		$this->type_compatibility_reporter = $type_compatibility_reporter;
		$this->name_resolver = $name_resolver;
		$this->infer_expression = $infer_expression;
		$this->infer_plain_identifier = $infer_plain_identifier;
	}

	public function check_class_member_declaration(IClassMemberDeclaration $node): void
	{
		if ($node instanceof MemberMappingDeclaration) {
			$this->check_member_mapping_declaration($node);
		}
		elseif ($node instanceof MethodDeclaration) {
			$this->check_method_declaration($node);
		}
		elseif ($node instanceof PropertyDeclaration || $node instanceof ObjectMember) {
			$this->declaration_checker->check_property_declaration($node);
		}
		elseif ($node instanceof ClassConstantDeclaration) {
			$this->declaration_checker->check_class_constant_declaration($node);
		}
		elseif ($node instanceof EnumCaseDeclaration) {
			$this->declaration_checker->check_enum_case_declaration($node);
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
				if ($item instanceof TypeReference && TypeHelper::get_type_symbol($item) === null) {
					TypeHelper::set_type_symbol($item, $this->name_resolver->find_symbol_for_plain_identifier($item));
				}
			}
		}
		else {
			$kind = get_class($node);
			throw $this->context->new_syntax_error("Unexpect class/interface member declaration kind: '{$kind}'", $node);
		}
	}

	public function check_method_declaration(MethodDeclaration $node): void
	{
		if ($this->context->is_checked($node)) return;
		$this->context->mark_checked($node);

		$member_bound_types = $this->snapshot_class_member_bound_types($node);
		$is_top_level_check = $this->context->get_function() === null;
		$this->clear_class_member_bound_types($member_bound_types);

		try {
			$this->function_body_resolver->check_function_kindred($node);
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

	public function preprocess_classkindred_declaration(ClassKindredDeclaration $node): void
	{
		$this->class_preprocessor->preprocess_classkindred_declaration($node);
	}

	public function check_classkindred_declaration(ClassKindredDeclaration $node): void
	{
		$this->context->is_classkindred_ready($node) or $this->preprocess_classkindred_declaration($node);

		$this->context->mark_checked($node);

		// first check the members in this class, and the inferred types will be used later
		foreach ($node->members as $member) {
			$this->check_class_member_declaration($member);
		}
	}

	private function check_member_mapping_declaration(MemberMappingDeclaration $node): void
	{
		$node->parameters && $this->declaration_checker->check_parameters_for_callable_declaration($node);
		$mapping_body = $node->body;

		// maybe need render, so check first
		$temp_block = $this->context->get_block();
		$this->context->set_block($node);
		$infered = ($this->infer_expression)($mapping_body);
		$this->context->set_block($temp_block);

		$hinted = $this->type_reference_checker->get_and_check_hinted_type($node);
		if ($hinted) {
			if ($infered and !$infered instanceof NoneType) {
				$this->type_compatibility_reporter->assert_type_compatible($hinted, $infered, $node);
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

	private function process_member_mapping(MemberMappingDeclaration $node): void
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
					($this->infer_plain_identifier)($item);
					if (ASTHelper::get_identifier_symbol($item)->declaration instanceof ConstantDeclaration) {
						$node->arguments_map[$dest_idx] = $item;
						continue;
					}

					throw $this->context->new_syntax_error("Identifier '{$item->name}' not defined in member mapping declaration", $item);
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
				throw $this->context->new_syntax_error("Unexpect expression in member mapping declaration", $item);
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
}
