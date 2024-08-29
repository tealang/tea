<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

abstract class BaseCoder
{
	const INDENT = "\t";

	const BLOCK_BEGIN = '{';

	const BLOCK_END = '}';

	const NOSPACE_PREFIX_OPERATORS = ['-', '~', '+', '&', '!'];

	const VAR_DECLARE_PREFIX = 'var ';

	const USE_DECLARE_PREFIX = 'use ';

	const NS_SEPARATOR = TeaParser::NS_SEPARATOR;

	const STATEMENT_TERMINATOR = '';

	const CLASS_MEMBER_TERMINATOR = '';

	const CLASS_MEMBER_OPERATOR = '.';

	const OBJECT_MEMBER_OPERATOR = '.';

	const DICT_KV_OPERATOR = ': ';

	const DICT_EMPTY_VALUE = '[:]';

	const VAL_NONE = _VAL_NONE;

	const PROGRAM_HEADER = LF; // an empty line

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
			if (!($node instanceof ClassKindredDeclaration) && !($node instanceof FunctionDeclaration)) {
				$simple_item = $node->render($this);
				$simple_item === null || $items[] = $simple_item;
			}
		}

		if (!empty($simple_item)) {
			$items[] = ''; // empty line
		}

		if ($program->initializer) {
			$body_items = $this->render_block_nodes($program->initializer->body);

			$items[] = '// ---------';
			$items[] = trim(join($body_items));
			$items[] = '// ---------';
			$items[] = '';
		}

		foreach ($program->declarations as $node) {
			if ($node instanceof ClassKindredDeclaration || $node instanceof FunctionDeclaration) {
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

	protected function generate_classkindred_header(ClassKindredDeclaration $node, string $kind)
	{
		$modifier = $this->get_declaration_modifier($node, _INTERNAL);
		$name = $this->get_declaration_name($node);
		return "$modifier $kind $name";
	}

	protected function get_declaration_modifier(IDeclaration $node, string $default_modifier = null)
	{
		if ($node->label) {
			$modifier = _SHARP . $node->label;
		}
		else {
			$modifier = $node->is_runtime ? _RUNTIME : ($node->modifier ?? $default_modifier);
		}

		return $modifier;
	}

	protected function get_declaration_name(IDeclaration $node)
	{
		$name = $node->name;
		if ($node->origin_name) {
			$name = "{$node->origin_name} as $name";
		}

		return $name;
	}

	protected function generate_class_bases(ClassKindredDeclaration $node)
	{
		$items = [];
		if ($node->inherits) {
			$items[] = $this->render_classkindred_identifier($node->inherits);
		}

		foreach ($node->bases as $item) {
			$items[] = $this->render_classkindred_identifier($item);
		}

		return $items ? ': ' . join(', ', $items) : '';
	}

	protected function generate_function_header(IFunctionDeclaration $node)
	{
		$items = [];
		if ($modifier = $this->get_declaration_modifier($node)) {
			$items[] = $modifier;
		}

		if ($node instanceof MethodDeclaration) {
			if ($node->is_static) {
				$items[] = _STATIC;
			}
		}
		else {
			$items[] = _FUNC;
		}

		$items[] = $this->get_declaration_name($node);

		// if ($node instanceof MethodDeclaration) {
		// 	if ($node->modifier) {
		// 		$items[] = $node->modifier;
		// 	}

		// 	if ($node->is_static) {
		// 		$items[] = _STATIC;
		// 	}
		// }
		// else {
		// 	$items[] = $node->label ? (_SHARP . $node->label) : ($node->modifier ?? _INTERNAL);
		// 	$items[] = _FUNC;
		// }

		// $items[] = $node->name;

		$prefix = join(' ', $items);

		$parameters = $this->render_parameters($node->parameters);
		$code = "{$prefix}($parameters)";

		// $callbacks = $node->callbacks ? $this->render_callback_protocols($node->callbacks) : '';

		$type = $this->render_type_expr_for_decl($node);
		if ($type !== null) {
			$code .= ' ' . $type;
		}

		return $code;
	}

	protected function generate_property_header(PropertyDeclaration $node)
	{
		if ($node->modifier) $items[] = $node->modifier;
		if ($node->is_static) $items[] = _STATIC;

		$items[] = $node->name;

		$type = $this->render_type_expr_for_decl($node);
		if ($type !== null) {
			$items[] = $type;
		}

		return join(' ', $items);
	}

	protected function generate_class_constant_header(ClassConstantDeclaration $node)
	{
		return $this->generate_constant_header($node);
	}

	protected function generate_constant_header(IConstantDeclaration $node)
	{
		$modifier = $this->get_declaration_modifier($node, _INTERNAL);
		$name = $this->get_declaration_name($node);

		$items = [
			$modifier,
			_CONST,
			$name
		];

		$type = $this->render_type_expr_for_decl($node);
		if ($type !== null) {
			$items[] = $type;
		}

		return join(' ', $items);
	}

	protected function render_type_expr_for_decl(IDeclaration $node)
	{
		$type = $node->declared_type;
		return $type && $type !== TypeFactory::$_void
			? $type->render($this)
			: null;
	}

	protected function render_parameters(array $nodes)
	{
		if (!$nodes) return '';

		foreach ($nodes as $node) {
			$items[] = $this->render_parameter_declaration($node);
		}

		return join(', ', $items);
	}

	// protected function render_callback_protocols(array $nodes)
	// {
	// 	foreach ($nodes as $node) {
	// 		$items[] = $this->render_callback_protocol($node);
	// 	}

	// 	return ' -> ' . join(' -> ', $items);
	// }

	// protected function render_callback_protocol(CallbackProtocol $node)
	// {
	// 	$async = $node->async ? 'async ' : '';
	// 	$parameters = $this->render_parameters($node->parameters);
	// 	$type = $this->render_type_expr_for_decl($node);

	// 	return "{$async}{$node->name}($parameters){$type}";
	// }

	// public function render_expect_declaration(ExpectDeclaration $node)
	// {
	// 	$parameters = $this->render_parameters($node->parameters);
	// 	return "#expect $parameters" . static::STATEMENT_TERMINATOR;
	// }

	public function render_masked_declaration(MaskedDeclaration $node)
	{
		$code = _MASKED . " {$node->name}";

		if ($node->parameters !== null) {
			$parameters = $this->render_parameters($node->parameters);
			$code .= "($parameters)";
		}

		$type = $this->render_type_expr_for_decl($node);
		if ($type !== null) {
			$code .= ' ' . $type;
		}

		// if ($node->callbacks) {
		// 	$callbacks = $this->render_callback_protocols($node->callbacks);
		// 	$code .= $callbacks;
		// }

		$statement = 'return ' . $node->body->render($this) . static::STATEMENT_TERMINATOR;
		$body = $this->wrap_block_code([$statement]);
		$code .= "\n" . $body;

		return $code;
	}

	public function render_method_declaration(MethodDeclaration $node)
	{
		$code = $this->generate_function_header($node);

		if ($node->body !== null) {
			$body = $this->render_function_body($node);
			$code = $code . ' ' . $body;
		}

		return $code;
	}

	public function render_function_declaration(FunctionDeclaration $node)
	{
		$code = $this->generate_function_header($node);

		if ($node->body !== null) {
			$body = $this->render_function_body($node);
			$code = $code . ' ' . $body;
		}

		return $code;
	}

	// public function render_function_block(FunctionBlock $node)
	// {
	// 	$declaration = $this->generate_function_header($node);
	// 	$body = $this->render_function_body($node);

	// 	return "{$declaration} $body";
	// }

	public function render_anonymous_function(AnonymousFunction $node)
	{
		$parameters = $this->render_parameters($node->parameters);
		$body = $this->render_function_body($node);

		return sprintf('(%s) => ', $parameters) . $body;
	}

	public function render_type_declaration(BuiltinTypeClassDeclaration $node)
	{
		$body = $this->render_block_nodes($node->members);

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, _TYPE),
			$this->generate_class_bases($node),
			$this->wrap_block_code($body)
		);

		return $code;
	}

	public function render_class_declaration(ClassDeclaration $node)
	{
		$body = $this->render_block_nodes($node->members);

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, _CLASS),
			$this->generate_class_bases($node),
			$this->wrap_block_code($body)
		);

		return $code;
	}

	public function render_interface_declaration(InterfaceDeclaration $node)
	{
		$body = $this->render_block_nodes($node->members);

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, _INTERFACE),
			$this->generate_class_bases($node),
			$this->wrap_block_code($body)
		);

		return $code;
	}

	public function render_intertrait_declaration(IntertraitDeclaration $node)
	{
		$body = $this->render_block_nodes($node->members);

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, _INTERTRAIT),
			$this->generate_class_bases($node),
			$this->wrap_block_code($body)
		);

		return $code;
	}

	public function render_trait_declaration(TraitDeclaration $node)
	{
		$body = $this->render_block_nodes($node->members);

		$code = sprintf("%s%s %s",
			$this->generate_classkindred_header($node, _TRAIT),
			$this->generate_class_bases($node),
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

	public function render_constant_declaration(IConstantDeclaration $node)
	{
		$code = $this->generate_constant_header($node);
		if ($node->value) {
			$code .= ' = ' . $node->value->render($this);
		}

		return $code . static::STATEMENT_TERMINATOR;
	}

	public function render_var_statement(VarStatement $node)
	{
		$items = [];
		foreach ($node->members as $member) {
			$member_code = $member->name;
			if ($member->value) {
				$member_code .= ' = ' . $member->value->render($this);
			}

			$items[] = $member_code;
		}

		$code = static::VAR_DECLARE_PREFIX . join(', ', $items);

		return $code . static::STATEMENT_TERMINATOR;
	}

	public function render_variable_declaration(VariableDeclaration $node)
	{
		$name = $this->get_variable_name($node);

		$code = static::VAR_DECLARE_PREFIX . $name;
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
		$expr = $this->get_variable_name($node);

		if ($node->is_inout) {
			$expr .= ' ' . _INOUT;
		}

		$type = $this->render_type_expr_for_decl($node);
		if ($type !== null) {
			$expr .= ' ' . $type;
		}

		if ($node->value) {
			$expr .= ' = ' . $node->value->render($this);
		}

		return $expr;
	}

	public function render_unset_statement(UnsetStatement $node)
	{
		return 'unset ' . $node->argument->render($this) . static::STATEMENT_TERMINATOR;
	}

	// public function render_array_element_assignment(ArrayElementAssignment $node)
	// {
	// 	$basing = $node->basing->render($this);
	// 	$key = $node->key ? $node->key->render($this) : '';
	// 	$value = $node->value->render($this);

	// 	return "{$basing}[{$key}] = {$value}" . static::STATEMENT_TERMINATOR;
	// }

	public function render_assignment_operation(AssignmentOperation $node)
	{
		return sprintf('%s %s %s',
			$node->left->render($this),
			$this->get_operator_sign($node->operator),
			$node->right->render($this)
		) . static::STATEMENT_TERMINATOR;
	}

	public function render_parentheses(Parentheses $node)
	{
		return '(' . $node->expression->render($this) . ')';
	}

	public function render_string_interpolation(StringInterpolation $node)
	{
		// $prefix = $node->escaping ? '#' : '$';
		$code = $node->content->render($this);
		// $code = "{$prefix}{$body}";

		return $code;
	}

	public function render_xtag(XTag $node)
	{
		$items = [];
		$subitems = $this->get_xtag_components($node);
		$this->merge_xtag_components($items, $subitems);
		$code = $this->render_xtag_components($items);

		return $code;
	}

	public function render_xtag_text(XTagText $node)
	{
		return $node->content;
	}

	protected function render_xtag_components(array $items)
	{
		$code = '';
		foreach ($items as $item) {
			if ($item instanceof BaseExpression) {
				$item = $item->render($this);
				$code .= '${' . $item . '}';
			}
			else {
				$code .= $item;
			}
		}

		return $code;
	}

	protected function merge_xtag_components(array &$items, ?array $new_items)
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

	private function get_xtag_components(XTag $node)
	{
		if ($node instanceof XTagComment) {
			return null; // do not render comments
		}

		$items = ["<{$node->name}"];

		$attr_items = $this->build_xtag_attribute_components($node);
		if ($attr_items) {
			$items = array_merge($items, $attr_items);
		}

		if ($node->children === null) {
			$items[] = $node->is_self_closing_tag ? '>' : ' />';
		}
		else {
			$items[] = '>';
			if ($node->inner_br) {
				$items[] = LF;
			}

			if ($node->children) {
				$subcomponents = $this->get_children_components($node->children);
				$items = array_merge($items, $subcomponents);
			}

			if ($node->closing_indents) {
				$items[] = $node->closing_indents;
			}

			$items[] = "</{$node->name}>";
		}

		return $items;
	}

	protected function get_children_components(array $children)
	{
		$items = [];
		foreach ($children as $child) {
			if ($child->indents) $items[] = $child->indents;

			if ($child instanceof XTag) {
				$subitems = $this->get_xtag_components($child);
				$this->merge_xtag_components($items, $subitems);
			}
			elseif ($child instanceof XTagText) {
				$items[] = $child->content;
			}
			elseif ($child instanceof XTagComment) {
				// ignore
			}
			else {
				$items[] = $child;
			}

			if ($child->tailing_br) {
				$items[] = LF;
			}
		}

		return $items;
	}

	protected function append_xtag_attribute_components(array &$items, XTag $node)
	{
		//
	}

	public function render_constant_identifier(ConstantIdentifier $node)
	{
		return $node->name;
	}

	public function render_variable_identifier(VariableIdentifier $node)
	{
		return $this->get_variable_name($node);
	}

	private function get_variable_name(VariableIdentifier|BaseVariableDeclaration $node)
	{
		$name = $node->name;
		if (TeaHelper::is_reserved($name)) {
			$name = '__' . $name;
		}

		return $name;
	}

	public function render_plain_identifier(PlainIdentifier $node)
	{
		return $node->name;
	}

	public function render_type_identifier(IType $node)
	{
		if ($node === TypeFactory::$_none) {
			return null;
		}

		if ($node instanceof CallableType) {
			$buffer = $this->render_callable_type($node);
		}
		elseif ($node instanceof IterableType and $node->generic_type) {
			$buffer = $node->name;
			$gtype = $node->generic_type;
			if ($gtype instanceof UnionType) {
				// $item = $this->render_union_type($gtype);
				// $item = "($item)";
				// $buffer = $item . '.' $buffer;
			}
			else {
				$item = $gtype->render($this);
				$buffer = $item . '.' . $buffer;
			}
		}
		else {
			$buffer = $node->name;
		}

		if ($node->nullable) {
			$buffer .= '?';
		}

		return $buffer;
	}

	public function render_union_type(UnionType $node)
	{
		$items = [];
		foreach ($node->get_members() as $member) {
			$item = $this->render_type_identifier($member);
			in_array($item, $items) or ($items[] = $item);
		}

		return join(_TYPE_UNION, $items);
	}

	protected function render_callable_type(CallableType $node)
	{
		if ($node === TypeFactory::$_callable) {
			return $node->name;
		}

		$parameters = $this->render_parameters($node->parameters);
		$type = $node->declared_type->render($this);

		return sprintf('(%s) %s', $parameters, $type);
	}

	public function render_classkindred_identifier(ClassKindredIdentifier $node)
	{
		$name = $node->name;

		if ($node->ns) {
			return $this->render_namespace_identifier($node->ns) . static::NS_SEPARATOR . $name;
		}

		return $name;
	}

	public function render_namespace_identifier(NamespaceIdentifier $node)
	{
		$names = $node->names;
		if ($node->based_unit) {
			array_unshift($names, $node->based_unit->ns->get_last_name());
		}

		return join(static::NS_SEPARATOR, $names);
	}

	public function render_accessing_identifier(AccessingIdentifier $node)
	{
		$basing = $node->basing->render($this);
		return sprintf('%s%s%s', $basing, static::OBJECT_MEMBER_OPERATOR, $node->name);
	}

	public function render_class_new(BaseExpression $node)
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
			$items[] = $arg ? $arg->render($this) : static::VAL_NONE;
		}

		$code = join(', ', $items);
		return $code;
	}

// ------

	public function render_line_comment(LineComment $node)
	{
		return _LINE_COMMENT_MARK . $node->content;
	}

	public function render_key_accessing(KeyAccessing $node)
	{
		$basing = $node->basing->render($this);
		$key = $node->key->render($this);

		return "{$basing}[{$key}]";
	}

	public function render_literal_default_mark(LiteralDefaultMark $node)
	{
		return '#default';
	}

	public function render_literal_none(LiteralNone $node)
	{
		return static::VAL_NONE;
	}

	public function render_bool_literal(LiteralBoolean $node)
	{
		return $node->value ? 'true' : 'false';
	}

	public function render_literal_integer(LiteralInteger $node)
	{
		return $node->value;
	}

	public function render_literal_float(LiteralFloat $node)
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

	public function render_plain_literal_string(PlainLiteralString $node)
	{
		$code = '\'' . $node->value . '\'';
		if ($node->label === _TEXT) {
			$code = '#text' . $code;
		}

		return $this->new_string_placeholder($code);
	}

	public function render_escaped_literal_string(EscapedLiteralString $node)
	{
		$code = '"' . $node->value . '"';
		if ($node->label === _TEXT) {
			$code = '#text' . $code;
		}

		return $this->new_string_placeholder($code);
	}

	public function render_plain_interpolated_string(PlainInterpolatedString $node)
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

	public function render_escaped_interpolated_string(EscapedInterpolatedString $node)
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

	// public function render_literal_object(LiteralObject $node)
	// {
	// 	return $this->render_object_expression($node);
	// }

	// public function render_literal_array(LiteralArray $node)
	// {
	// 	return $this->render_array_expression($node);
	// }

	// public function render_literal_dict(LiteralDict $node)
	// {
	// 	if (!$node->items) {
	// 		return static::DICT_EMPTY_VALUE;
	// 	}

	// 	return $this->render_dict_expression($node);
	// }

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
		if (!$node->items) {
			return static::DICT_EMPTY_VALUE;
		}

		$body = $this->render_dict_members($node->items, $node->is_vertical_layout);
		return $this->wrap_dict($body);
	}

	protected function render_dict_members(array $subnodes, bool $is_vertical_layout)
	{
		$items = [];
		foreach ($subnodes as $subnode) {
			$items[] = $subnode->render($this);
		}

		return $this->join_member_items($items, $is_vertical_layout);
	}

	public function render_dict_member(DictMember $node)
	{
		$key = $node->key->render($this);
		$value = $node->value->render($this);
		return $key . static::DICT_KV_OPERATOR . $value;
	}

	public function render_object_member(ObjectMember $node)
	{
		$key = $this->render_key_for_object_member($node);
		$value = $node->value->render($this);
		return $key . static::DICT_KV_OPERATOR . $value;
	}

	protected function render_key_for_object_member(ObjectMember $node)
	{
		return $node->key_quote_mark ? "'{$node->name}'" : $node->name;
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

	public function render_object_expression(BaseExpression $node)
	{
		$body = $this->render_object_members($node->class_declaration->members, $node->is_vertical_layout);
		return $this->wrap_object($body);
	}

	protected function render_object_members(array $subnodes, bool $is_vertical_layout)
	{
		$items = [];
		foreach ($subnodes as $subnode) {
			if ($subnode->is_dynamic) {
				continue;
			}

			$items[] = $subnode->render($this);
		}

		return $this->join_member_items($items, $is_vertical_layout);
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

	public function render_as_operation(AsOperation $node)
	{
		$left = $node->left->render($this);
		$right = $node->right->render($this);

		return "$left#$right";
	}

	public function render_is_operation(IsOperation $node)
	{
		$left = $node->left->render($this);
		$right = $node->right->render($this);
		$operator = $node->not ? 'is not' : 'is';

		return "$left $operator $right";
	}

	// public function render_reference_operation(ReferenceOperation $node)
	// {
	// 	$expression = $node->identifier->render($this);
	// 	return _REFERENCE . $expression;
	// }

	public function render_prefix_operation(BaseExpression $node)
	{
		$expr_code = $node->expression->render($this);

		$oper = $this->get_operator_sign($node->operator);
		if (!in_array($oper, self::NOSPACE_PREFIX_OPERATORS, true)) {
			$oper .= ' ';
		}

		return $oper . $expr_code;
	}

	// public function render_postfix_operation(BaseExpression $node)
	// {
	// 	$expression = $node->expression->render($this);
	// 	return $expression . $node->operator;
	// }

	public function render_binary_operation(BinaryOperation $node)
	{
		$operator = $this->get_operator_sign($node->operator);
		$left = $node->left->render($this);
		$right = $node->right->render($this);

		return sprintf('%s %s %s', $left, $operator, $right);
	}

	protected function get_operator_sign(Operator $oper)
	{
		return $oper->tea_sign;
	}

	public function render_none_coalescing_operation(NoneCoalescingOperation $node)
	{
		$items = [];
		foreach ($node->items as $item) {
			$items[] = $item->render($this);
		}

		return join(' ?? ', $items);
	}

	public function render_ternary_expression(TernaryExpression $node)
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

	public function render_use_statement(UseStatement $node)
	{
		$uri = $this->render_namespace_identifier($node->ns);
		$uri = ltrim($uri, static::NS_SEPARATOR);

		$code = static::USE_DECLARE_PREFIX . $uri;

		if ($node->targets) {
			$code .= $this->generate_use_targets($node->targets);
		}

		return $code . static::STATEMENT_TERMINATOR;
	}

	protected function generate_use_targets(array $targets)
	{
		$items = [];
		foreach ($targets as $target) {
			$items[] = $target->source_name ? "{$target->source_name} as {$target->target_name}" : $target->target_name;
		}

		return sprintf(' { %s }', join(', ', $items));
	}

	public function render_forin_block(ForInBlock $node)
	{
		$iterable = $node->iterable->render($this);
		$value_var = $node->value_var->render($this);
		$body = $this->render_control_structure_body($node);

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

		return $code . $this->render_control_structure_body($node);
	}

	public function render_while_block(WhileBlock $node)
	{
		$test = $node->condition->render($this);
		$body = $this->render_control_structure_body($node);

		// return $node->do_the_first ? "while #first $test $body" : "while $test $body";

		return "while $test $body";
	}

	public function render_loop_block(LoopBlock $node)
	{
		$body = $this->render_control_structure_body($node);
		return sprintf('loop %s', $body);
	}

	public function render_if_block(IfBlock $node)
	{
		$items = [];
		$items[] = sprintf('if (%s) %s', $node->condition->render($this), $this->render_control_structure_body($node));

		if ($node->else) {
			$items[] = $node->else->render($this);
		}

		$code = join($items);

		if ($node->has_exceptional()) {
			$code = $this->wrap_with_except_block($node, $code);
		}

		return $code;
	}

	protected function wrap_with_except_block(IExceptAble $node, string $code)
	{
		$items = [];
		$code = $this->indents($code);
		$items[] = "try {\n{$code}\n}";

		foreach ($node->catchings as $block) {
			$items[] = $this->render_catch_block($block);
		}

		if ($node->finally) {
			$items[] = $this->render_finally_block($node->finally);
		}

		return join("\n", $items);
	}

	public function render_elseif_block(ElseIfBlock $node)
	{
		$items = [];
		$items[] = sprintf("\nelseif (%s) %s", $node->condition->render($this), $this->render_control_structure_body($node));

		if ($node->else) {
			$items[] = $node->else->render($this);
		}

		return join($items);
	}

	public function render_else_block(ElseBlock $node)
	{
		return "\nelse " . $this->render_control_structure_body($node);
	}

	public function render_catch_block(CatchBlock $node)
	{
		$var = static::VAR_DECLARE_PREFIX . $node->var->name;
		$type = $node->var->declared_type->render($this);

		return "catch ($type $var) " . $this->render_control_structure_body($node);
	}

	public function render_finally_block(FinallyBlock $node)
	{
		return "finally " . $this->render_control_structure_body($node);
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

	// protected function render_with_post_condition(PostConditionAbleStatement $node, string $code)
	// {
	// 	return $code . ' when ' . $node->condition->render($this);
	// }

	public function render_break_statement(Node $node)
	{
		$argument = $node->argument ? ' #' . $node->argument : '';
		$code = 'break' . $argument;

		// if ($node->condition) {
		// 	$code = $this->render_with_post_condition($node, $code);
		// }

		return $code;
	}

	public function render_continue_statement(Node $node)
	{
		$argument = $node->argument ? ' #' . $node->argument : '';
		$code = 'continue' . $argument;

		// if ($node->condition) {
		// 	$code = $this->render_with_post_condition($node, $code);
		// }

		return $code;
	}

	public function render_return_statement(Node $node)
	{
		$statement = $node->argument ? "return " . $node->argument->render($this) : 'return';
		$code = $statement . static::STATEMENT_TERMINATOR;

		// if ($node->condition) {
		// 	$code = $this->render_with_post_condition($node, $code);
		// }

		return $code;
	}

	public function render_throw_statement(Node $node)
	{
		$code = "throw " . $node->argument->render($this) . static::STATEMENT_TERMINATOR;

		// if ($node->condition) {
		// 	$code = $this->render_with_post_condition($node, $code);
		// }

		return $code;
	}

	public function render_exit_statement(Node $node)
	{
		$argument = $node->argument ? ' ' . $node->argument->render($this) : '';
		$code = 'exit' . $argument . static::STATEMENT_TERMINATOR;

		// if ($node->condition) {
		// 	$code = $this->render_with_post_condition($node, $code);
		// }

		return $code;
	}

	public function render_normal_statement(NormalStatement $statement)
	{
		$expr = $statement->expression
			? $statement->expression->render($this)
			: '';

		return $expr . static::STATEMENT_TERMINATOR;
	}

	public function render_function_body(IScopeBlock $node)
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

	public function render_control_structure_body(IBlock $node)
	{
		$code = $this->render_block_nodes($node->body);
		return $this->wrap_block_code($code);
	}

	protected function wrap_block_code(array $items)
	{
		$body = trim(join($items));

		$code = $this->begin_tag() . LF;
		$code .= $this->indents($body === '' ? '// no any' : $body);
		$code .= LF . $this->end_tag();

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

			if ($node->leading_br) {
				$item = LF . $item;
			}

			$items[] = $item . LF;
		}

		return $items;
	}

	public function render_yield_expression(YieldExpression $node)
	{
		$argument = $node->argument->render($this);
		return _YIELD . ' ' . $argument;
	}

	// public function render_expression_list(ExpressionList $expr)
	// {
	// 	$items = [];
	// 	foreach ($expr->items as $subexpr) {
	// 		$items[] = $subexpr->render($this);
	// 	}

	// 	return join(', ', $items);
	// }

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

	protected function end_tag()
	{
		return static::BLOCK_END;
	}

	protected function new_paragraph(string $contents)
	{
		return LF . $contents . LF;
	}
}

// end
