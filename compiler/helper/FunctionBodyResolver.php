<?php
/**
 * This file is part of the Tea programming language project
 * @copyright 	(c)2019 tealang.org
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

class FunctionBodyResolver
{
	private CheckerContext $context;
	private DeclarationChecker $declaration_checker;
	private TypeReferenceChecker $type_reference_checker;
	private GotoResolver $goto_resolver;
	private TypeCompatibilityReporter $type_compatibility_reporter;
	private BlockStatementResolver $block_statement_resolver;

	public function __construct(
		CheckerContext $context,
		DeclarationChecker $declaration_checker,
		TypeReferenceChecker $type_reference_checker,
		GotoResolver $goto_resolver,
		TypeCompatibilityReporter $type_compatibility_reporter,
		BlockStatementResolver $block_statement_resolver
	) {
		$this->context = $context;
		$this->declaration_checker = $declaration_checker;
		$this->type_reference_checker = $type_reference_checker;
		$this->goto_resolver = $goto_resolver;
		$this->type_compatibility_reporter = $type_compatibility_reporter;
		$this->block_statement_resolver = $block_statement_resolver;
	}

	public function check_function_kindred(IFunctionDeclaration $node): void
	{
		$this->declaration_checker->check_parameters_for_callable_declaration($node);

		$hinted = $this->type_reference_checker->get_and_check_hinted_type($node);
		if ($hinted instanceof BaseType && $hinted->name === _TYPE_SELF) {
			$decl = $node->belong_block;
			$hinted = $decl->typing_identifier;
		}

		$infered = $node->body ? $this->infer_function_body($node, $hinted) : null;

		$node->infered_type =  $infered ?? $hinted ?? TypeFactory::$_void;
	}

	public function infer_function_body(IFunctionDeclaration $node, ?BaseType $hinted): ?BaseType
	{
		$prev_func = $this->context->get_function();
		$this->context->set_function($node); // for find 'super' in methods

		try {
			if (is_array($node->body)) {
				$infered = $this->goto_resolver->infer_with_collected_labels(
					$node,
					fn(IBlock $block): ?BaseType => $this->block_statement_resolver->infer_block($block)
				);
			}
			else {
				$infered = $this->block_statement_resolver->infer_single_expression_block($node);
			}

			if ($hinted) {
				if ($infered !== null) {
					if (!$this->type_compatibility_reporter->is_type_compatible($hinted, $infered, $node, 'return') && $this->type_compatibility_reporter->should_report_type_mismatch($hinted, $infered, $node, 'return')) {
						if ($node instanceof BaseDeclaration && $this->type_compatibility_reporter->warn_phpdoc_only_type_mismatch($node, $hinted, $infered, $node, 'return')) {
							return $infered;
						}

						$infered_name = ASTChecker::get_type_name($infered);
						$hinted_name = ASTChecker::get_type_name($hinted);
						throw $this->context->new_syntax_error("The infered return type '{$infered_name}' is incompatible with the hinted '{$hinted_name}'", $node);
					}
				}
				elseif (!TypeHelper::is_same_type($hinted, TypeFactory::$_void)
					&& !TypeHelper::is_same_or_based_type($hinted, TypeFactory::$_generator)
					&& !$this->block_ends_with_control_transfer($node)) {
					throw $this->context->new_syntax_error("Function required a return type '{$hinted->name}'", $node);
				}
			}
		}
		finally {
			$this->context->set_function($prev_func);
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
}
