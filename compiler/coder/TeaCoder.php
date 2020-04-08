<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class TeaCoder
{
	const INDENT = "\t";

	const BLOCK_BEGIN = '{';

	const BLOCK_END = '}';

	const VAR_PREFIX = '';

	const VAR_DECLARE_PREFIX = 'var ';

	const NS_SEPARATOR = '.';

	const STATEMENT_TERMINATOR = '';

	const CLASS_MEMBER_TERMINATOR = '';

	const CLASS_MEMBER_OPERATOR = '.';

	const OBJECT_MEMBER_OPERATOR = '.';

	const DICT_KV_OPERATOR = ': ';

	const DICT_EMPTY_VALUE = '[:]';

	const NONE = 'none';

	const PROGRAM_HEADER = LF; // a empty line

	protected const STRING_HOLDER_MARK = "\r\r:%d:";

	protected $program;

	protected $temp_name_index = 0;

	private $string_holder_count = 0;
	private $string_holder_marks = [];
	private $string_holder_contents = [];

	public function render_program(Program $program)
	{
		$this->program = $program;
		$items = $this->render_program_statements($program);
		return $this->join_code($items);
	}

	protected function join_code(array $items)
	{
		$code = join(LF, $items);

		// for do not indents to string blocks
		if ($this->string_holder_count) {
			for ($i = $this->string_holder_count - 1; $i >= 0; $i--) {
				$code = str_replace($this->string_holder_marks[$i], $this->string_holder_contents[$i], $code);
			}
		}

		return sprintf("%s\n%s\n// program end\n", static::PROGRAM_HEADER, $code);
	}

	protected function render_program_statements(Program $program)
	{
		$items = [];
		if ($program->uses) {
			$items[] = $this->render_uses($program->uses) . LF;
		}

		foreach ($program->declarations as $node) {
			if (!$node instanceof ClassLikeDeclaration && !$node instanceof FunctionDeclaration) {
				$simple_item = $node->render($this);
				$simple_item === null || $items[] = $simple_item;
			}
		}

		if (!empty($simple_item)) {
			$items[] = ''; // empty line
		}

		if ($program->main_function) {
			$body_items = $this->render_block_nodes($program->main_function->body);

			$items[] = '// ---------';
			$items[] = trim(join($body_items));
			$items[] = '// ---------';
			$items[] = '';
		}

		foreach ($program->declarations as $node) {
			if ($node instanceof ClassLikeDeclaration || $node instanceof FunctionDeclaration) {
				$item = $node->render($this);
				$item === null || $items[] = $item . LF;
			}
		}

		return $items;
	}

	protected function render_uses(array $statements)
	{
		$items = [];
		foreach ($statements as $statement) {
			$items[] = $statement->render($this);
		}

		return join(LF, $items);
	}

// -----------

	protected function generate_class_header(ClassLikeDeclaration $node, string $kind = null)
	{
		if ($node instanceof BuiltinTypeClassDeclaration) {
			$prefix = _SHARP . 'tea';
		}
		elseif ($node->label === _PHP) {
			$prefix = _SHARP . _PHP;
			if ($node->origin_name) {
				$prefix .= " $node->origin_name as";
			}
		}
		else {
			$prefix = $node->modifier ?? _INTERNAL;
		}

		return "$prefix $node->name";
	}

	protected function generate_class_baseds(ClassLikeDeclaration $node)
	{
		$items = [];
		if ($node->inherits) {
			$items[] = $this->render_classlike_identifier($node->inherits);
		}

		foreach ($node->baseds as $item) {
			$items[] = $this->render_classlike_identifier($item);
		}

		return $items ? ': ' . join(', ', $items) : '';
	}

	protected function generate_function_header(FunctionDeclaration $node)
	{
		if ($node->label) {
			$items[] = _SHARP . $node->label;
		}
		elseif ($node->modifier) {
			$items[] = $node->modifier;
		}

		if ($node->is_static) $items[] = _STATIC;

		$items[] = $node->name;

		$header = join(' ', $items);
		return $header;
	}

	protected function generate_property_header(PropertyDeclaration $node)
	{
		if ($node->modifier) $items[] = $node->modifier;
		if ($node->is_static) $items[] = _STATIC;

		$items[] = $node->name;
		$items[] = $node->type->render($this);

		return join(' ', $items);
	}

	protected function generate_class_constant_header(ClassConstantDeclaration $node)
	{
		$code = $node->modifier ? "$node->modifier " : '';
		$code = "{$code}{$node->name}";

		if ($node->type) {
			$code .= ' ' . $node->type->render($this);
		}

		return $code;
	}

	protected function generate_constant_header(ConstantDeclaration $node)
	{
		$code = $node->modifier ? "$node->modifier " : '';
		$code = "{$code}{$node->name}";

		if ($node->type) {
			$code .= ' ' . $node->type->render($this);
		}

		return $code;
	}

	protected function generate_type(IDeclaration $node)
	{
		return $node->type && $node->type !== TypeFactory::$_void ? ' ' . $node->type->render($this) : '';
	}

	protected function render_parameters(array $nodes)
	{
		if (!$nodes) return '';

		foreach ($nodes as $node) {
			$items[] = $this->render_parameter_declaration($node);
		}

		return join(', ', $items);
	}

	protected function render_callback_protocols(array $nodes)
	{
		foreach ($nodes as $node) {
			$items[] = $this->render_callback_protocol($node);
		}

		return ' -> ' . join(' -> ', $items);
	}

	protected function render_callback_protocol(CallbackProtocol $node)
	{
		$async = $node->async ? 'async ' : '';
		$parameters = $this->render_parameters($node->parameters);
		$type = $this->generate_type($node);

		return "{$async}{$node->name}($parameters){$type}";
	}

	public function render_expect_declaration(ExpectDeclaration $node)
	{
		$parameters = $this->render_parameters($node->parameters);
		return "#expect $parameters" . static::STATEMENT_TERMINATOR;
	}

	public function render_masked_declaration(MaskedDeclaration $node)
	{
		$header = _MASKED . " {$node->name}";
		$type = $this->generate_type($node);

		$statement = 'return ' . $node->body->render($this) . static::STATEMENT_TERMINATOR;
		$body = $this->wrap_block_code([$statement]);

		if ($node->parameters === null && $node->callbacks === null) {
			return "{$header}{$type}\n$body";
		}
		else {
			$parameters = $this->render_parameters($node->parameters);
			$callbacks = $node->callbacks ? $this->render_callback_protocols($node->callbacks) : '';

			return "{$header}($parameters){$type}{$callbacks}\n$body";
		}
	}

	protected function render_function_protocol(FunctionDeclaration $node)
	{
		$header = $this->generate_function_header($node);
		$parameters = $this->render_parameters($node->parameters);
		// $callbacks = $node->callbacks ? $this->render_callback_protocols($node->callbacks) : '';
		$type = $this->generate_type($node);

		return "{$header}($parameters){$type}";
	}

	public function render_function_declaration(FunctionDeclaration $node)
	{
		$code = $this->render_function_protocol($node);

		if ($node->body !== null) {
			$body = $this->render_enclosing_block($node);
			$code = $code . ' ' . $body;
		}

		return $code;
	}

	// public function render_function_block(FunctionBlock $node)
	// {
	// 	$declaration = $this->render_function_protocol($node);
	// 	$body = $this->render_enclosing_block($node);

	// 	return "{$declaration} $body";
	// }

	public function render_lambda_expression(IExpression $node)
	{
		$parameters = $this->render_parameters($node->parameters);
		$body = $this->render_enclosing_block($node);

		return sprintf('(%s) => ', $parameters) . $body;
	}

	public function render_class_declaration(ClassDeclaration $node)
	{
		$body = $this->render_block_nodes($node->members);

		$code = sprintf("%s%s %s",
			$this->generate_class_header($node, 'class'),
			$this->generate_class_baseds($node),
			$this->wrap_block_code($body)
		);

		return $code;
	}

	public function render_interface_declaration(InterfaceDeclaration $node)
	{
		$body = $this->render_block_nodes($node->members);

		$code = sprintf("%s%s %s",
			$this->generate_class_header($node, 'interface'),
			$this->generate_class_baseds($node),
			$this->wrap_block_code($body)
		);

		return $code;
	}

	public function render_property_declaration(PropertyDeclaration $node)
	{
		$code = $this->generate_property_header($node);

		if ($node->value) {
			$code .= ' = ' . $node->value->render($this);
		}

		return $code . static::CLASS_MEMBER_TERMINATOR;
	}

	public function render_class_constant_declaration(ClassConstantDeclaration $node)
	{
		$code = $this->generate_class_constant_header($node);
		if ($node->value) {
			$code .= ' = ' . $node->value->render($this);
		}

		return $code . static::CLASS_MEMBER_TERMINATOR;
	}

	public function render_constant_declaration(ConstantDeclaration $node)
	{
		$code = $this->generate_constant_header($node);
		if ($node->value) {
			$code .= ' = ' . $node->value->render($this);
		}

		return $code . static::STATEMENT_TERMINATOR;
	}

	public function render_variable_declaration(VariableDeclaration $node)
	{
		$code = static::VAR_DECLARE_PREFIX . $node->name;
		if ($node->value) {
			$code .= ' = ' . $node->value->render($this);
		}

		return $code . static::STATEMENT_TERMINATOR;
	}

	public function render_super_variable_declaration(SuperVariableDeclaration $node)
	{
		$code = static::VAR_DECLARE_PREFIX . $node->name;
		if ($node->value) {
			$code .= ' = ' . $node->value->render($this);
		}

		return $code . static::STATEMENT_TERMINATOR;
	}

	public function render_parameter_declaration(ParameterDeclaration $node)
	{
		$expr = static::VAR_PREFIX . $node->name;
		if ($node->is_value_mutable) {
			$expr = $expr . ' ' . _MUT;
		}

		if ($node->type) {
			$type = $node->type->render($this);
			if ($type) {
				$expr = "{$expr} {$type}";
			}
		}

		if ($node->value) {
			$expr .= ' = ' . $node->value->render($this);
		}

		return $expr;
	}

	public function render_array_element_assignment(ArrayElementAssignment $node)
	{
		$master = $node->master->render($this);
		$key = $node->key ? $node->key->render($this) : '';
		$value = $node->value->render($this);

		return "{$master}[{$key}] = {$value}" . static::STATEMENT_TERMINATOR;
	}

	public function render_normal_assignment(Assignment $node)
	{
		return sprintf('%s = %s',
			$node->master->render($this),
			$node->value->render($this)
		) . static::STATEMENT_TERMINATOR;
	}

	public function render_compound_assignment(CompoundAssignment $node)
	{
		return sprintf('%s %s %s',
			$node->master->render($this),
			$node->operator,
			$node->value->render($this)
		) . static::STATEMENT_TERMINATOR;
	}

	public function render_parentheses(Parentheses $node)
	{
		return '(' . $node->expression->render($this) . ')';
	}

	public function render_html_escape_expression(HTMLEscapeExpression $node)
	{
		$expr = $node->expression->render($this);
		return "#{$expr}";
	}

	public function render_xblock(XBlock $node)
	{
		$items = [];
		foreach ($node->items as $item) {
			$subitems = $this->get_expressions_by_xelements($item);
			$subitems[] = $item->post_spaces;

			$this->merge_xblock_items($items, $subitems);
		}

		return $this->render_xblock_elements($items);
	}

	protected function render_xblock_elements(array $items)
	{
		$code = '';
		foreach ($items as $item) {
			if ($item instanceof IExpression) {
				$item = $item->render($this);
				$code .= '${' . $item . '}';
			}
			else {
				$code .= $item;
			}
		}

		return $code;
	}

	protected function merge_xblock_items(array &$items, $new_items)
	{
		if (!$new_items) {
			return;
		}

		foreach ($new_items as $item) {
			if (is_object($item)) {
				$items[] = $item;
			}
			else {
				$last_idx = count($items) - 1;
				if ($last_idx >= 0 && is_string($items[$last_idx])) {
					$items[$last_idx] .= $item;
				}
				else {
					$items[] = $item;
				}
			}
		}
	}

	protected function get_expressions_by_xelements(XBlockElement $node)
	{
		if ($node instanceof XBlockComment) {
			return null; // do not render comments
		}

		if (is_object($node->name)) {
			$items = ['<', $node->name];
		}
		else {
			$items = ["<{$node->name}"];
		}

		foreach ($node->attributes as $attr) {
			if (is_string($attr)) {
				$this->add_string_to_xchildren($items, $attr);
			}
			else {
				$items[] = $attr;
			}
		}

		if ($node->children === null) {
			$item = $node instanceof XBlockLeaf ? '>' : '/>';
			$this->add_string_to_xchildren($items, $item);
			return $items;
		}

		$this->add_string_to_xchildren($items, '>');

		foreach ($node->children as $element) {
			if ($element instanceof XBlockElement) {
				$subitems = $this->get_expressions_by_xelements($element);
				$this->merge_xblock_items($items, $subitems);
			}
			elseif (is_string($element)) {
				$this->add_string_to_xchildren($items, $element);
			}
			else {
				$items[] = $element;
			}
		}

		if (is_object($node->name)) {
			$this->add_string_to_xchildren($items, '</');
			$items[] = $node->name;
			$items[] = '>';
		}
		else {
			$this->add_string_to_xchildren($items, "</{$node->name}>");
		}

		return $items;
	}

	protected static function add_string_to_xchildren(array &$items, string $element)
	{
		$last_idx = count($items) - 1;
		if (is_string($items[$last_idx])) {
			$items[$last_idx] .= $element;
		}
		else {
			$items[] = $element;
		}
	}

	public function render_constant_identifier(ConstantIdentifier $node)
	{
		return $node->name;
	}

	public function render_variable_identifier(VariableIdentifier $node)
	{
		return $node->name;
	}

	public function render_plain_identifier(PlainIdentifier $node)
	{
		return $node->name;
	}

	public function render_type_identifier(BaseType $node)
	{
		if ($node === TypeFactory::$_none) {
			return null;
		}
		elseif ($node instanceof IterableType) {
			if ($node->value_type) {
				return $node->value_type->render($this) . '.' . $node->name;
			}
		}
		elseif ($node instanceof CallableType) {
			return $this->render_callable_type($node);
		}

		return $node->name;
	}

	public function render_union_type_identifier(UnionType $node)
	{
		return _ANY;
	}

	protected function render_callable_type(CallableType $node)
	{
		if ($node === TypeFactory::$_callable) {
			return $node->name;
		}

		$parameters = $this->render_parameters($node->parameters);
		$type = $node->type->render($this);

		return sprintf('(%s) %s', $parameters, $type);
	}

	public function render_classlike_identifier(ClassLikeIdentifier $node)
	{
		$name = $node->name;
		if ($node->ns) {
			return $this->render_namespace_identifier($node->ns) . static::NS_SEPARATOR . $name;
		}

		return $name;
	}

	// public function render_class_identifier(ClassIdentifier $node)
	// {
	// 	return $this->render_classlike_identifier($node);
	// }

	public function render_namespace_identifier(NamespaceIdentifier $node)
	{
		return $node->uri;
	}

	public function render_accessing_identifier(AccessingIdentifier $node)
	{
		$master = $node->master->render($this);
		return sprintf('%s%s%s', $master, static::OBJECT_MEMBER_OPERATOR, $node->name);
	}

	public function render_class_new(IExpression $node)
	{
		$class = $node->class->render($this);

		$arguments = $node->normalized_arguments ?? $node->arguments;
		$arguments = $this->render_arguments($arguments);

		return "new {$class}($arguments)";
	}

	public function render_call_expression(CallExpression $node)
	{
		$callee = $node->callee->render($this);

		$arguments = $node->normalized_arguments ?? $node->arguments;
		$arguments = $this->render_arguments($node->arguments);

		$code = "{$callee}($arguments)";

		if ($node->callbacks) {
			foreach ($node->callbacks as $cb) {
				$code .= " -> {$cb->name}: " . $cb->value->render($this);
			}
		}

		return $code;
	}

	protected function render_arguments(array $nodes)
	{
		if (!$nodes) return '';

		$items = [];
		foreach ($nodes as $arg) {
			$items[] = $arg ? $arg->render($this) : 'null';
		}

		$code = join(', ', $items);
		return $code;
	}

// ------

	public function render_inline_comments(InlineComments $node)
	{
		return _INLINE_COMMENT_MARK . join(_INLINE_COMMENT_MARK, $node->items);
	}

	public function render_key_accessing(KeyAccessing $node)
	{
		$master = $node->left->render($this);
		$key = $node->right->render($this);

		return "{$master}[{$key}]";
	}

	public function render_none_literal(NoneLiteral $node)
	{
		if ($node === ASTFactory::$default_value_marker) {
			return '#default';
		}

		return static::NONE;
	}

	public function render_bool_literal(BooleanLiteral $node)
	{
		return $node->value ? 'true' : 'false';
	}

	public function render_int_literal(IntegerLiteral $node)
	{
		return $node->value;
	}

	public function render_uint_literal(IntegerLiteral $node)
	{
		return $this->render_int_literal($node);
	}

	public function render_float_literal(FloatLiteral $node)
	{
		return $node->value;
	}

	protected function new_string_placeholder(string $quoted_string)
	{
		// do not need holder when have not any new lines
		if (strpos($quoted_string, LF) === false) {
			return $quoted_string;
		}

		$mark = sprintf(self::STRING_HOLDER_MARK, $this->string_holder_count++);
		$this->string_holder_marks[] = $mark;
		$this->string_holder_contents[] = $quoted_string;

		return $mark;
	}

	public function render_unescaped_string_literal(UnescapedStringLiteral $node)
	{
		$code = '\'' . $node->value . '\'';
		if ($node->label === _TEXT) {
			$code = '#text' . $code;
		}

		return $this->new_string_placeholder($code);
	}

	public function render_escaped_string_literal(EscapedStringLiteral $node)
	{
		$code = '"' . $node->value . '"';
		if ($node->label === _TEXT) {
			$code = '#text' . $code;
		}

		return $this->new_string_placeholder($code);
	}

	public function render_unescaped_string_interpolation(UnescapedStringInterpolation $node)
	{
		$tmp = '';
		foreach ($node->items as $item) {
			if (is_string($item)) {
				$tmp .= $item;
			}
			else {
				$item = $item->render($this);
				$tmp .= "$\{$item\}";
			}
		}

		$code = "'$tmp'";
		return $this->new_string_placeholder($code);
	}

	public function render_escaped_string_interpolation(EscapedStringInterpolation $node)
	{
		$tmp = '';
		foreach ($node->items as $item) {
			if (is_string($item)) {
				$tmp .= $item;
			}
			else {
				$item = $item->render($this);
				$tmp .= "$\{$item\}";
			}
		}

		$code = "\"$tmp\"";
		return $this->new_string_placeholder($code);
	}

	public function render_object_literal(ObjectLiteral $node)
	{
		return $this->render_object_expression($node);
	}

	public function render_array_literal(ArrayLiteral $node)
	{
		return $this->render_array_expression($node);
	}

	public function render_dict_literal(DictLiteral $node)
	{
		if (!$node->items) {
			return static::DICT_EMPTY_VALUE;
		}

		return $this->render_dict_expression($node);
	}

	public function render_array_expression(ArrayExpression $node)
	{
		$items = [];
		foreach ($node->items as $item) {
			$items[] = $item->render($this);
		}

		$body = $this->join_member_items($items, $node->is_vertical_layout);

		return "[$body]";
	}

	public function render_dict_expression(DictExpression $node)
	{
		return $this->render_dict_with_subitems($node->items, $node->is_vertical_layout);
	}

	protected function render_dict_with_subitems(array $subnodes, bool $is_vertical_layout)
	{
		$items = [];
		foreach ($subnodes as $subnode) {
			$key = $this->render_dict_key($subnode->key);
			$value = $subnode->value->render($this);
			$items[] = $key . static::DICT_KV_OPERATOR . $value;
		}

		$body = $this->join_member_items($items, $is_vertical_layout);

		return $this->wrap_dict($body);
	}

	protected function render_dict_key(IExpression $expr)
	{
		return $expr->render($this);
	}

	protected function join_member_items(array $items, bool $is_vertical_layout)
	{
		if ($is_vertical_layout) {
			$code = $this->indents(join(",\n", $items));
			$code = LF . $code . LF;
		}
		else {
			$code = join(', ', $items);
		}

		return $code;
	}

	protected function wrap_dict(string $body)
	{
		return '[' . $body . ']';
	}

	protected function wrap_object(string $body)
	{
		return '{' . $body . '}';
	}

	public function render_object_expression(IExpression $node)
	{
		$items = [];
		foreach ($subnodes as $key => $value) {
			$items[] = $this->render_object_item($key, $value);
		}

		$body = $this->join_member_items($items, $node->is_vertical_layout);

		return $this->wrap_object($body);
	}

	protected function render_object_item(string $key, IExpression $value)
	{
		$value = $value->render($this);
		return "$key: $value";
	}

	// public function render_functional_operation(FunctionalOperation $node)
	// {
	// 	$items = [];
	// 	foreach ($node->arguments as $arg) {
	// 		$item = $arg->render($this);
	// 		if (strpos($item, ' ')) {
	// 			$item = "($item)";
	// 		}

	// 		$items[] = $item;
	// 	}

	// 	return join(" {$node->operator} ", $items);
	// }

	public function render_cast_operation(CastOperation $node)
	{
		$left = $node->left->render($this);
		$right = $node->right->render($this);

		return "$left as $right";
	}

	public function render_is_operation(IsOperation $node)
	{
		$left = $node->left->render($this);
		$right = $node->right->render($this);
		$operator = $node->is_not ? 'is not' : 'is';

		return "$left $operator $right";
	}

	// public function render_reference_operation(ReferenceOperation $node)
	// {
	// 	$expression = $node->identifier->render($this);
	// 	return _REFERENCE . $expression;
	// }

	public function render_prefix_operation(IExpression $node)
	{
		$expression = $node->expression->render($this);
		return $node->operator->sign . $expression;
	}

	// public function render_postfix_operation(IExpression $node)
	// {
	// 	$expression = $node->expression->render($this);
	// 	return $expression . $node->operator;
	// }

	public function render_binary_operation(BinaryOperation $node)
	{
		$operator = $node->operator->sign;
		$left = $node->left->render($this);
		$right = $node->right->render($this);

		return sprintf('%s %s %s', $left, $operator, $right);
	}

	public function render_none_coalescing_operation(NoneCoalescingOperation $node)
	{
		$items = [];
		foreach ($node->items as $item) {
			$items[] = $item->render($this);
		}

		return join(' ?? ', $items);
	}

	public function render_conditional_expression(ConditionalExpression $node)
	{
		if ($node->then === null) {
			$code = sprintf('%s ?: %s',
				$node->condition->render($this),
				$node->else->render($this)
			);
		}
		else {
			$code = sprintf('%s ? %s : %s',
				$node->condition->render($this),
				$node->then->render($this),
				$node->else->render($this)
			);
		}

		return $code;
	}

	// public function render_relay_expression(RelayExpression $node)
	// {
	// 	$expr = $node->argument->render($this);
	// 	foreach ($node->callees as $callee) {
	// 		$callee = $callee->render($this);
	// 		$expr .= ", {$callee}";
	// 	}

	// 	return $expr;
	// }

	public function render_regular_expression(RegularExpression $node)
	{
		return "/{$node->pattern}/{$node->flags}";
	}

	public function render_newline_statement()
	{
		return LF;
	}

	public function render_use_statement(UseStatement $node)
	{
		$ns = $this->render_namespace_identifier($node->ns);

		$code = "use $ns";

		if ($node->targets) {
			$code .= $this->generate_use_targets($node->targets);
		}

		return $code . static::STATEMENT_TERMINATOR;
	}

	protected function generate_use_targets(array $targets)
	{
		$items = [];
		foreach ($targets as $target) {
			$items[] = $target->source_name ? "$source_name as $target_name" : $target_name;
		}

		return sprintf(' { %s }', join(', ', $items));
	}

	public function render_forin_block(ForInBlock $node)
	{
		$iterable = $node->iterable->render($this);
		$value_var = $node->value_var->render($this);
		$body = $this->render_block($node);

		if ($node->key_var) {
			$key_var = $node->key_var->render($this);
			return sprintf('for %s, %s in %s %s', $key_var, $value_var, $iterable, $body);
		}
		else {
			return sprintf('for %s in %s %s', $value_var, $iterable, $body);
		}
	}

	public function render_forto_block(ForToBlock $node)
	{
		$var = $node->var->render($this);
		$start = $node->start->render($this);
		$end = $node->end->render($this);

		$code = "for {$var} = {$start} ";
		$code .= $node->is_downto_mode ? _DOWNTO : _TO;
		$code .= " {$end} ";
		if ($node->step) {
			$code .= "step {$node->step} ";
		}

		return $code . $this->render_block($node);
	}

	public function render_while_block(WhileBlock $node)
	{
		$test = $node->condition->render($this);
		$body = $this->render_block($node);

		return $node->do_the_first
			? "while is first $test $body"
			: "while $test $body";
	}

	public function render_loop_block(LoopBlock $node)
	{
		$body = $this->render_block($node);
		return sprintf('loop %s', $body);
	}

	public function render_if_block(IfBlock $node)
	{
		$items = [];
		$items[] = sprintf('if (%s) %s', $node->condition->render($this), $this->render_block($node, 'if'));

		if ($node->else) {
			$items[] = $node->else->render($this);
		}

		$code = join($items);

		if ($node->except) {
			$code = $this->wrap_with_except_block($node->except, $code);
		}

		return $code;
	}

	protected function wrap_with_except_block(CatchBlock $catch, string $code)
	{
		$items = [];
		$code = $this->indents($code);
		$items[] = "try {\n{$code}\n}";
		$items[] = $this->render_catch_block($catch);

		return join($items);
	}

	public function render_elseif_block(ElseIfBlock $node)
	{
		$items = [];
		$items[] = sprintf("\nelseif (%s) %s", $node->condition->render($this), $this->render_block($node, 'elseif'));

		if ($node->else) {
			$items[] = $node->else->render($this);
		}

		return join($items);
	}

	public function render_else_block(ElseBlock $node)
	{
		return "\nelse " . $this->render_block($node, _ELSE);
	}

	public function render_catch_block(CatchBlock $node)
	{
		$var = static::VAR_DECLARE_PREFIX . $node->var->name;
		$type = $node->var->type->render($this);

		$items = [];
		$items[] = "\ncatch ($type $var) " . $this->render_block($node, _CATCH);

		if ($node->except) {
			$items[] = $node->except->render($this);
		}

		return join($items);
	}

	public function render_finally_block(FinallyBlock $node)
	{
		return "\nfinally " . $this->render_block($node, _FINALLY);
	}

	public function render_echo_statement(EchoStatement $node)
	{
		$fn = $node->end_newline ? 'echo' : 'print';

		if ($node->arguments) {
			$arguments = $this->render_arguments($node->arguments);
			return "$fn $arguments";
		}
		else {
			return '$fn';
		}
	}

	protected function render_with_post_condition(PostConditionAbleStatement $node, string $code)
	{
		return $code . ' when ' . $node->condition->render($this);
	}

	public function render_break_statement(Node $node)
	{
		$argument = $node->argument ? ' #' . $node->argument : '';
		$code = 'break' . $argument;

		if ($node->condition) {
			$code = $this->render_with_post_condition($node, $code);
		}

		return $code;
	}

	public function render_continue_statement(Node $node)
	{
		$argument = $node->argument ? ' #' . $node->argument : '';
		$code = 'continue' . $argument;

		if ($node->condition) {
			$code = $this->render_with_post_condition($node, $code);
		}

		return $code;
	}

	public function render_return_statement(Node $node)
	{
		$statement = $node->argument ? "return " . $node->argument->render($this) : 'return';
		$code = $statement . static::STATEMENT_TERMINATOR;

		if ($node->condition) {
			$code = $this->render_with_post_condition($node, $code);
		}

		return $code;
	}

	public function render_throw_statement(Node $node)
	{
		$code = "throw " . $node->argument->render($this) . static::STATEMENT_TERMINATOR;

		if ($node->condition) {
			$code = $this->render_with_post_condition($node, $code);
		}

		return $code;
	}

	public function render_exit_statement(Node $node)
	{
		$argument = $node->argument ? ' ' . $node->argument->render($this) : '';
		$code = 'exit' . $argument . static::STATEMENT_TERMINATOR;

		if ($node->condition) {
			$code = $this->render_with_post_condition($node, $code);
		}

		return $code;
	}

	public function render_normal_statement(NormalStatement $statement)
	{
		return $statement->expression->render($this) . static::STATEMENT_TERMINATOR;
	}

	public function render_class_members(array $members)
	{
		return $this->render_block_nodes($members);
	}

	public function render_enclosing_block(IEnclosingBlock $node)
	{
		if (is_array($node->body)) {
			$code = $this->render_block_nodes($node->body);
			$code = $this->wrap_block_code($code);
		}
		else {
			// the single expression lambda body
			$code = $node->body->render($this);
		}

		return $code;
	}

	public function render_block(BaseBlock $node, string $label = null)
	{
		$code = $this->render_block_nodes($node->body);
		return $this->wrap_block_code($code, $label);
	}

	protected function wrap_block_code(array $items, string $label = null)
	{
		$body = trim(join($items));

		$code = $this->begin_tag() . LF;
		$code .= $this->indents($body === '' ? '// no any' : $body);
		$code .= LF . $this->end_tag($label);

		return $code;
	}

	protected function render_block_nodes(array $nodes)
	{
		$items = [];
		foreach ($nodes as $node) {
			$item = $node->render($this);
			if ($item === null) {
				continue;
			}

			if ($node->leading !== null) {
				$items[] = $node->leading . $item . LF;
			}
			else {
				$items[] = $item . LF;
			}
		}

		return $items;
	}

	public function render_include_expression(IncludeExpression $expr)
	{
		return "#include({$expr->target})";
	}

	public function render_yield_expression(YieldExpression $node)
	{
		$argument = $node->argument->render($this);
		return _YIELD . ' ' . $argument;
	}

	public function render_expression_list(ExpressionList $expr)
	{
		$items = [];
		foreach ($expr->items as $subexpr) {
			$items[] = $subexpr->render($this);
		}

		return join(', ', $items);
	}

	protected function indents(string $contents, $number = 1)
	{
		$indents = str_repeat(static::INDENT, $number);

		if (strpos($contents, LF) === false) {
			return $indents . $contents;
		}

		return $indents . preg_replace('/\n([^\n]+)/', "\n{$indents}$1", $contents);
	}

	protected function begin_tag()
	{
		return static::BLOCK_BEGIN;
	}

	protected function end_tag(string $kind = null)
	{
		return static::BLOCK_END;
	}

	protected function new_paragraph(string $contents)
	{
		return LF . $contents . LF;
	}

	protected function generate_temp_variable_name()
	{
		return static::VAR_PREFIX . '__tmp' . $this->temp_name_index++;
	}
}
