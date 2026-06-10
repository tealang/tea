<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ValueTrust
{
	public const CHECKED_LOCAL = 'checked_local';
	public const RUNTIME_ENSURED = 'runtime_ensured';
	public const TRUSTED_DECLARATION = 'trusted_declaration';
	public const PHP_NATIVE_SIGNATURE = self::TRUSTED_DECLARATION;
	public const HEADER_ONLY = 'header_only';
	public const PHPDOC_ONLY = 'phpdoc_only';
	public const UNKNOWN = 'unknown';
}

class OutputSafety
{
	public const HTML_TEXT = 'html_text';
	public const HTML_ATTRIBUTE = 'html_attribute';
	private const HTML_SAFE_ATTRIBUTE = 'HtmlSafe';

	private static ?\SplObjectStorage $value_trusts = null;
	private static array $html_safe_bound_value_stack = [];

	public static function reset_semantic_state(): void
	{
		self::$value_trusts = null;
		self::$html_safe_bound_value_stack = [];
	}

	public static function get_value_trust(BaseExpression $expr): string
	{
		$table = self::$value_trusts;
		if ($table !== null && isset($table[$expr])) {
			return $table[$expr];
		}

		return ValueTrust::UNKNOWN;
	}

	public static function set_value_trust(BaseExpression $expr, ?string $trust): void
	{
		if ($trust === null || $trust === ValueTrust::UNKNOWN) {
			if (self::$value_trusts !== null) {
				unset(self::$value_trusts[$expr]);
			}
			return;
		}

		$table = self::get_value_trust_table();
		$table[$expr] = $trust;
	}

	public static function can_skip_html_escape(BaseExpression $expr, string $sink): bool
	{
		if ($sink !== self::HTML_TEXT && $sink !== self::HTML_ATTRIBUTE) {
			return false;
		}

		if (self::is_html_safe_expression($expr)) {
			return true;
		}

		return self::is_structurally_safe_scalar_expression($expr);
	}

	private static function get_value_trust_table(): \SplObjectStorage
	{
		return self::$value_trusts ??= new \SplObjectStorage();
	}

	private static function is_structurally_safe_scalar_expression(BaseExpression $expr): bool
	{
		$type = ASTHelper::get_expressed_type($expr);
		if ($type === null || !self::is_html_safe_scalar_type($type)) {
			return false;
		}

		if (self::has_checked_scalar_value_trust($expr)) {
			return true;
		}

		if ($expr instanceof Identifiable && self::is_checked_parameter_or_property_safe_scalar($expr)) {
			return true;
		}

		if ($expr instanceof Identifiable && self::is_trusted_numeric_bound_value($expr)) {
			return true;
		}

		if ($expr instanceof BaseCallExpression && self::is_checked_numeric_call($expr)) {
			return true;
		}

		return $expr instanceof LiteralInteger
			|| $expr instanceof LiteralFloat
			|| ($expr instanceof BinaryOperation && OperatorFactory::is_number_operator($expr->operator));
	}

	private static function is_html_safe_scalar_type(BaseType $type): bool
	{
		if ($type instanceof IntType || $type instanceof FloatType || $type instanceof BoolType) {
			return true;
		}

		if ($type instanceof UnionType) {
			foreach ($type->get_members() as $member) {
				if (!self::is_html_safe_scalar_type($member)) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	private static function is_checked_parameter_or_property_safe_scalar(Identifiable $expr): bool
	{
		$decl = ASTHelper::get_identifier_symbol($expr)->declaration ?? null;
		if (!$decl instanceof ParameterDeclaration && !$decl instanceof PropertyDeclaration) {
			return false;
		}

		$program = $decl->program;
		return !$decl->is_extern
			&& ($program === null || $program->source_dialect !== Program::SOURCE_DIALECT_HEADER);
	}

	private static function is_trusted_numeric_bound_value(Identifiable $expr): bool
	{
		$decl = ASTHelper::get_identifier_symbol($expr)->declaration ?? null;
		if (!$decl instanceof IVariableDeclaration) {
			return false;
		}

		$bound_value = TypeHelper::get_bound_value($decl);
		if ($bound_value === null) {
			return false;
		}

		$type = ASTHelper::get_expressed_type($bound_value);
		if ($type === null || !TypeHelper::is_number_type($type)) {
			return false;
		}

		return self::has_checked_scalar_value_trust($bound_value)
			|| ($bound_value instanceof BaseCallExpression && self::is_checked_numeric_call($bound_value))
			|| $bound_value instanceof LiteralInteger
			|| $bound_value instanceof LiteralFloat
			|| ($bound_value instanceof BinaryOperation && OperatorFactory::is_number_operator($bound_value->operator));
	}

	private static function is_checked_numeric_call(BaseCallExpression $expr): bool
	{
		return self::has_checked_scalar_value_trust($expr);
	}

	private static function has_checked_scalar_value_trust(BaseExpression $expr): bool
	{
		return in_array(
			self::get_value_trust($expr),
			[ValueTrust::CHECKED_LOCAL, ValueTrust::TRUSTED_DECLARATION, ValueTrust::RUNTIME_ENSURED],
			true
		);
	}

	private static function is_html_safe_expression(BaseExpression $expr): bool
	{
		if ($expr instanceof Parentheses) {
			return self::is_html_safe_expression($expr->expression);
		}

		if ($expr instanceof AsOperation) {
			if ($expr->right_source_name === _PLAIN && $expr->right instanceof PlainType) {
				return true;
			}

			return self::is_html_safe_expression($expr->left);
		}

		if ($expr instanceof Identifiable && self::is_html_safe_bound_value($expr)) {
			return true;
		}

		if ($expr instanceof BaseCallExpression && self::is_html_escape_call($expr)) {
			return true;
		}

		if ($expr instanceof BinaryOperation && $expr->operator->is(OPID::CONCAT)) {
			return self::is_html_safe_expression($expr->left)
				&& self::is_html_safe_expression($expr->right);
		}

		if ($expr instanceof TernaryExpression && $expr->then !== null) {
			return self::is_html_safe_expression($expr->then)
				&& self::is_html_safe_expression($expr->else);
		}

		if ($expr instanceof PlainLiteralString || $expr instanceof EscapedLiteralString) {
			return TeaHelper::is_pure_string($expr->value);
		}

		if ($expr instanceof LiteralNone) {
			return true;
		}

		if ($expr instanceof PlainInterpolatedString || $expr instanceof EscapedInterpolatedString) {
			return self::is_html_safe_interpolated_string($expr, self::HTML_ATTRIBUTE);
		}

		return false;
	}

	private static function is_html_safe_interpolated_string(InterpolatedString $expr, string $sink): bool
	{
		foreach ($expr->items as $item) {
			if (is_string($item)) {
				if (!TeaHelper::is_pure_string($item)) {
					return false;
				}
			}
			elseif (!$item instanceof StringInterpolation
				|| !self::can_skip_html_escape($item->content, $sink)) {
				return false;
			}
		}

		return true;
	}

	private static function is_html_escape_call(BaseCallExpression $expr): bool
	{
		$decl = ASTHelper::get_callee_declaration($expr);
		return $decl instanceof BaseDeclaration
			&& self::has_attribute($decl, self::HTML_SAFE_ATTRIBUTE);
	}

	private static function is_html_safe_bound_value(Identifiable $expr): bool
	{
		$decl = ASTHelper::get_identifier_symbol($expr)->declaration ?? null;
		if (!$decl instanceof IVariableDeclaration) {
			return false;
		}

		$key = spl_object_id($decl);
		if (isset(self::$html_safe_bound_value_stack[$key])) {
			return false;
		}

		$bound_value = TypeHelper::get_bound_value($decl);
		if ($bound_value === null) {
			return false;
		}

		self::$html_safe_bound_value_stack[$key] = true;
		try {
			return self::is_html_safe_expression($bound_value);
		}
		finally {
			unset(self::$html_safe_bound_value_stack[$key]);
		}
	}

	private static function has_attribute(BaseDeclaration $decl, string $name): bool
	{
		foreach ($decl->attributes as $attribute) {
			if ($attribute->identifier->name === $name) {
				return true;
			}
		}

		return false;
	}
}
