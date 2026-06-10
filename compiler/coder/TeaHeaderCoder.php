<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaHeaderCoder extends BaseCoder
{
	const PROGRAM_HEADER = "// the public declarations\n";
	private const HTML_SAFE_ATTRIBUTE = 'HtmlSafe';

	protected function process_use_statments(Program $program)
	{
		$program->uses = []; // clear the custom use items

		foreach ($program->declarations as $node) {
			$this->dig_using_statements($program, $node);
		}
	}

	protected function dig_using_statements(Program $program, BaseDeclaration $decl)
	{
		$unit_header_program = $program->unit->programs['__package'] ?? null;
		if ($unit_header_program) {
			// the non targets use statements used for namespace search
			foreach ($unit_header_program->uses as $use_statement) {
				if (!$use_statement->targets) {
					$program->uses[$use_statement->ns->uri . '!'] = $use_statement;
				}
			}
		}

		foreach ($decl->uses as $use) {
			$uri = $use->ns->uri;
			if ($uri === '') {
				continue;
			}

			if (!isset($program->uses[$uri])) {
				$program->uses[$uri] = new UseStatement($use->ns);
			}

			$program->uses[$uri]->append_target($use);
		}
	}

	protected function render_program_statements(Program $program)
	{
		$this->process_use_statments($program);

		$items = parent::render_program_statements($program);

		$uri = ltrim($program->unit->ns->uri, static::NS_SEPARATOR);
		$unit_declaration = _NAMESPACE . " {$uri}\n";
		array_unshift($items, $unit_declaration);

		return $items;
	}

	public function render_class_constant_declaration(ClassConstantDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		$code = $this->generate_class_constant_header($node);

		return $code . static::CLASS_MEMBER_TERMINATOR;
	}

	public function render_constant_declaration(IConstantDeclaration $node)
	{
		if ($node->modifier === _INTERNAL) {
			return null;
		}

		$code = $this->generate_constant_header($node);

		return $code . static::STATEMENT_TERMINATOR;
	}

	public function render_property_declaration(PropertyDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		$code = $this->generate_property_header($node);
		return $code . static::CLASS_MEMBER_TERMINATOR;
	}

	public function render_method_declaration(MethodDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		return $this->render_function_attributes($node) . $this->generate_function_header($node);
	}

	public function render_parameter_declaration(ParameterDeclaration $node)
	{
		if (!$node->value || $this->is_safe_public_default_value($node->value)) {
			return parent::render_parameter_declaration($node);
		}

		$original_value = $node->value;
		$node->value = ASTFactory::$default_value_mark;
		try {
			return parent::render_parameter_declaration($node);
		}
		finally {
			$node->value = $original_value;
		}
	}

	private function is_safe_public_default_value(BaseExpression $node): bool
	{
		if ($node instanceof LiteralExpression) {
			return true;
		}

		if ($node instanceof PlainIdentifier) {
			$decl = ASTHelper::get_identifier_symbol($node)->declaration ?? null;
			return $decl instanceof BaseDeclaration
				&& $decl->modifier !== _PRIVATE
				&& $decl->modifier !== _INTERNAL;
		}

		if ($node instanceof ArrayExpression) {
			foreach ($node->items as $item) {
				if (!$this->is_safe_public_default_value($item)) {
					return false;
				}
			}

			return true;
		}

		if ($node instanceof DictExpression) {
			foreach ($node->items as $item) {
				if (!$this->is_safe_public_default_value($item->key)
					|| !$this->is_safe_public_default_value($item->value)) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	public function render_function_declaration(FunctionDeclaration $node)
	{
		if ($node->modifier === _PRIVATE || $node->modifier === _INTERNAL) {
			return null;
		}

		return $this->render_function_attributes($node) . $this->generate_function_header($node);
	}

	private function render_function_attributes(IFunctionDeclaration $node): string
	{
		return $this->render_html_safe_attribute($node) . $this->render_type_assertion_attributes($node);
	}

	private function render_html_safe_attribute(IFunctionDeclaration $node): string
	{
		foreach ($node->attributes as $attribute) {
			if ($attribute->identifier->name === self::HTML_SAFE_ATTRIBUTE) {
				return '#[HtmlSafe]' . "\n";
			}
		}

		return '';
	}

	private function render_type_assertion_attributes(IFunctionDeclaration $node): string
	{
		$assertions = ASTHelper::get_php_true_assertions($node);
		if (!$assertions) {
			return '';
		}

		$items = [];
		foreach ($assertions as $index => $type) {
			$parameter = $node->parameters[$index] ?? null;
			if (!$parameter instanceof ParameterDeclaration) {
				continue;
			}

			$items[] = '#[TypeAssertion(' . $parameter->name . ' is ' . $this->render_node($type) . ')]';
		}

		if (!$items) {
			return '';
		}

		return join("\n", $items) . "\n";
	}

	public function render_member_mapping_declaration(MemberMappingDeclaration $node)
	{
		// Native method/property mapping in builtin types
		$type = $this->render_type_expr_for_decl($node);

		if ($node->is_property) {
			$code = "{$node->name} {$type}";
		}
		else {
			$parameters = $node->parameters ? $this->render_parameters($node->parameters) : '';
			$callbacks = $node->callbacks ? $this->render_callback_protocols($node->callbacks) : '';

			$code = "{$node->name}($parameters) {$type}{$callbacks}";
		}

		if ($node->body !== null) {
			$code .= ' ' . _DOUBLE_ARROW . ' ' . $this->render_member_mapping_body_expression($node);
		}

		return $code;
	}

	public function render_class_declaration(ClassDeclaration|EnumDeclaration $node)
	{
		$body = $this->render_block_nodes($node->members);

		return sprintf("%s%s %s",
			$this->generate_classkindred_header($node, _CLASS),
			$this->generate_class_bases($node),
			$this->wrap_block_code($body)
		);
	}

	protected function generate_classkindred_header(ClassKindredDeclaration $node, string $kind)
	{
		return $this->render_builtin_feature_attributes($node) . parent::generate_classkindred_header($node, $kind);
	}

	private function render_builtin_feature_attributes(ClassKindredDeclaration $node): string
	{
		$items = [];
		foreach ($node->attributes as $attribute) {
			if ($attribute->identifier->name !== 'BuiltinFeature') {
				continue;
			}

			$feature_names = [];
			foreach ($attribute->arguments as $argument) {
				$name = $this->get_builtin_feature_name($argument);
				if ($name !== null) {
					$feature_names[] = $name;
				}
			}

			if ($feature_names) {
				$items[] = '#[BuiltinFeature(' . join(', ', $feature_names) . ')]';
			}
		}

		return $items ? join("\n", $items) . "\n" : '';
	}

	private function get_builtin_feature_name(BaseExpression|BaseType $argument): ?string
	{
		if ($argument instanceof PlainIdentifier || $argument instanceof TypeReference) {
			return $argument->name;
		}

		if ($argument instanceof LiteralString) {
			return $argument->value;
		}

		return null;
	}

	public function render_trait_declaration(TraitDeclaration $node)
	{
		$members = [];
		foreach ($node->members as $name => $member) {
			if ($member instanceof BaseDeclaration && $member->is_virtual) {
				continue;
			}

			$members[$name] = $member;
		}

		$body = $this->render_block_nodes($members);

		return sprintf("%s%s %s",
			$this->generate_classkindred_header($node, _TRAIT),
			$this->generate_class_bases($node),
			$this->wrap_block_code($body)
		);
	}

	public function render_traits_using_statement(TraitsUsingStatement $node)
	{
		$items = [];
		foreach ($node->items as $item) {
			$items[] = $this->render_classkindred_identifier($item);
		}

		return static::USE_DECLARE_PREFIX . join(', ', $items) . static::CLASS_MEMBER_TERMINATOR;
	}

	protected function render_type_expr_for_decl(BaseDeclaration $node)
	{
		$declared = $node->declared_type;
		$buffer = $declared === null || $declared instanceof VoidType
			? null
			: $this->render_node($declared);

		if (ASTHelper::get_noted_type($node) instanceof BaseType && $this->should_render_header_noted_type($node)) {
			$noted = $this->normalize_header_noted_type($node);
			$buffer .= _SHARP . $this->render_node($noted);
		}
		elseif ($declared === null
			&& $node->infered_type instanceof BaseType
			&& $this->is_valid_public_type($node->infered_type)) {
			// noted to declared by default
			$buffer .= _SHARP . $this->render_node($node->infered_type);
		}
		elseif ($this->should_render_native_php_untyped_return_as_mixed($node)) {
			$buffer .= _SHARP . $this->render_node(TypeFactory::$_mixed);
		}

		return $buffer;
	}

	private function should_render_header_noted_type(BaseDeclaration $node): bool
	{
		$noted = ASTHelper::get_noted_type($node);
		if (!$noted instanceof BaseType || !$this->is_valid_public_type($noted)) {
			return false;
		}

		$declared = $node->declared_type;
		if (!$declared instanceof BaseType) {
			return true;
		}

		$noted = $this->normalize_header_noted_type($node);
		return $this->render_node($declared) !== $this->render_node($noted);
	}

	private function normalize_header_noted_type(BaseDeclaration $node): BaseType
	{
		$declared = $node->declared_type;
		$noted = ASTHelper::get_noted_type($node);
		if ($declared instanceof BaseType
			&& $noted instanceof BaseType
			&& ASTHelper::is_noted_type_nullable_inherited($node)
			&& TypeHelper::is_nullable_type($declared)
			&& TypeHelper::is_nullable_type($noted)) {
			return TypeHelper::to_non_nullable($noted);
		}

		return $noted;
	}

	private function should_render_native_php_untyped_return_as_mixed(BaseDeclaration $node): bool
	{
		$program = $node->program;
		if ($program === null && $node->belong_block instanceof BaseDeclaration) {
			$program = $node->belong_block->program;
		}

		return ($node instanceof FunctionDeclaration || $node instanceof MethodDeclaration)
			&& $node->declared_type === null
			&& $node->name !== _CONSTRUCT
			&& $program?->is_native === true
			&& $this->has_return_value($node);
	}

	private function has_return_value(IFunctionDeclaration $node): bool
	{
		return $this->block_has_return_value($node);
	}

	private function block_has_return_value(IBlock $block): bool
	{
		if (!is_array($block->body)) {
			return false;
		}

		foreach ($block->body as $statement) {
			if ($statement instanceof ReturnStatement && $statement->argument !== null) {
				return true;
			}

			if ($statement instanceof IBlock && $this->block_has_return_value($statement)) {
				return true;
			}

			if ($statement instanceof BaseIfBlock || $statement instanceof SwitchBlock) {
				if ($this->else_chain_has_return_value($statement->else)) {
					return true;
				}
			}

			if ($statement instanceof IExceptAble && $this->exceptable_has_return_value($statement)) {
				return true;
			}
		}

		return false;
	}

	private function else_chain_has_return_value(?IElseBlock $branch): bool
	{
		while ($branch instanceof IBlock) {
			if ($this->block_has_return_value($branch)) {
				return true;
			}

			$branch = $branch instanceof ElseIfBlock ? $branch->else : null;
		}

		return false;
	}

	private function exceptable_has_return_value(IExceptAble $node): bool
	{
		foreach ($node->catchings as $catching) {
			if ($this->block_has_return_value($catching)) {
				return true;
			}
		}

		return $node->finally !== null && $this->block_has_return_value($node->finally);
	}

	private function is_valid_public_type(BaseType $type): bool
	{
		$name = $type->name ?? '';
		return $name !== '' && $name[0] !== _DOLLAR;
	}
}

// end
