<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class ObjectExpressionResolver
{
	private ClassBodyResolver $class_body_resolver;

	public function __construct(ClassBodyResolver $class_body_resolver)
	{
		$this->class_body_resolver = $class_body_resolver;
	}

	public function infer_object_expression(ObjectExpression $node): BaseType
	{
		$symbol = ASTHelper::get_object_expression_symbol($node);
		$this->class_body_resolver->check_classkindred_declaration($symbol->declaration);

		$infered = clone TypeFactory::$_object;
		TypeHelper::set_type_symbol($infered, $symbol);

		return $infered;
	}
}
