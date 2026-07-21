<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class MarkupLiteralResolver
{
	private CheckerContext $context;
	private $infer_expression;

	public function __construct(CheckerContext $context, callable $infer_expression)
	{
		$this->context = $context;
		$this->infer_expression = $infer_expression;
	}

	public function infer_plain_interpolated_string(PlainInterpolatedString $node): BaseType
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

		return $is_pure ? TypeFactory::$_text : TypeFactory::$_string;
	}

	public function infer_escaped_interpolated_string(EscapedInterpolatedString $node): BaseType
	{
		foreach ($node->items as $item) {
			if ($item instanceof StringInterpolation) {
				$this->infer_interpolation($item);
			}
		}

		return TypeFactory::$_string;
	}

	public function infer_xtag(XTag $node): BaseType
	{
		$fixed_attr_map = $node->fixed_attributes;
		$dyn_expr = $node->dynamic_attributes;
		$children = $node->children ?? [];

		foreach ($fixed_attr_map as $item) {
			if ($item instanceof XTagAttrInterpolation) {
				$this->infer_interpolation($item);
			}
			elseif ($item instanceof BaseExpression) {
				$infered = ($this->infer_expression)($item);
				if (!TypeHelper::is_string_concatable_type($infered)) {
					$type_name = ASTChecker::get_type_name($infered);
					throw $this->context->new_syntax_error("Expect scalar type value, {$type_name} given", $item);
				}
			}
			elseif ($item === true) {
				// true value item
			}
			else {
				throw $this->context->new_syntax_error("Invalid xtag attribute value", $item);
			}
		}

		if ($dyn_expr) {
			$infered = ($this->infer_expression)($dyn_expr);
			if (!$infered instanceof DictType) {
				throw $this->context->new_syntax_error("Type of activity attributes expression must be Dict<String>", $dyn_expr);
			}
		}

		foreach ($children as $item) {
			if ($item instanceof XTag) {
				$this->infer_xtag($item);
			}
			elseif ($item instanceof XTagChildInterpolation) {
				$infered = $this->infer_interpolation($item);
				if (!TypeHelper::is_xtag_child_type($infered)) {
					$type_name = ASTChecker::get_type_name($infered);
					throw $this->context->new_syntax_error("Expect String/IView/List<IView> type value, {$type_name} given", $item);
				}
			}
			elseif ($item instanceof XTagElement) {
				// text/comment
			}
			else {
				$infered = ($this->infer_expression)($item);
				if (!TypeHelper::is_string_concatable_type($infered)) {
					$type_name = ASTChecker::get_type_name($infered);
					throw $this->context->new_syntax_error("Expect scalar type value, {$type_name} given", $item);
				}
			}
		}

		return TypeFactory::$_xview;
	}

	private function infer_interpolation(Interpolation $interpolation): BaseType
	{
		$infered = ($this->infer_expression)($interpolation->content);
		ASTHelper::set_expressed_type($interpolation, $infered);
		return $infered;
	}
}
