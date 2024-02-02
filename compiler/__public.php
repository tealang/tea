<?php
/**
 * This file is part of the Tea programming language project
 *
 * @author 		Benny <benny@meetdreams.com>
 * @copyright 	(c)2019 YJ Technology Ltd. [http://tealang.org]
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Tea;

require __DIR__ . '/config.php';
require __DIR__ . '/constants.php';

const UNIT_PATH = __DIR__ . DIRECTORY_SEPARATOR;

const LF = "\n";

// array_key_last undefined in version <= 7.2
if (!function_exists('array_key_last')) {
	function array_key_last(array $items) {
		$keys = array_keys($items);
		return end($keys);
	}
}

function halt(string $msg) {
	echo LF, $msg, LF, LF;
	exit;
}

function error(string $msg) {
	echo "\nError: $msg\n";
}

function println(string ...$contents) {
	foreach ($contents as $content) {
		echo $content;
	}

	echo LF;
}

function dump(...$args) {
	echo LF;
	$dumper = new Dumper(['unit', 'program']);
	foreach ($args as $arg) {
		$str = $dumper->stringify($arg, 0);
		$str = str_replace('Tea\\', '', $str);
		echo $str, LF;
	}
}

function strip_unit_path(string $file) {
	static $prefix_len;
	if ($prefix_len === null) {
		$prefix_len = strlen(UNIT_PATH);
	}

	return substr($file, $prefix_len);
}

// process the options of command-line interface
function process_cli_options(array $argv, array $allow_list = []) {
	$opts = [];
	for ($i = 1; $i < count($argv); $i++) {
		$item = $argv[$i];
		if ($item[0] === '-' && strlen($item) > 1) {
			if ($item[1] === '-') {
				// the '--key' style
				$key = substr($item, 2);
			}
			elseif (strlen($item) === 2) {
				// the '-k' style
				$key = substr($item, 1);
			}
			else {
				throw new Exception("Invalid command-line option '{$item}'");
			}

			if (!in_array($key, $allow_list, true)) {
				throw new Exception("Invalid command-line option key '{$key}'");
			}

			$opts[$key] = true;
		}
		else {
			$opts[] = $item;
		}
	}

	return $opts;
}

function get_traces(int $trace_start = 0) {
	$traces = '';
	$trace_items = debug_backtrace();
	$len = count($trace_items) - 1;
	for ($i = $trace_start + 1; $i < $len; $i++) {
		$item = $trace_items[$i];

		$args = [];
		foreach ($item['args'] as $arg) {
			$args[] = json_encode($arg, JSON_UNESCAPED_UNICODE);
		}

		$traces .= sprintf("%s:%d \t%s(%s)\n",
			$item['file'],
			$item['line'],
			$item['function'],
			join(', ', $args)
		);
	}

	return $traces;
}

// Please do not modify the following contents
# --- generates ---
const __AUTOLOADS = [
	'Tea\Compiler' => 'Compiler.php',
	'Tea\Exception' => 'Exception.php',
	'Tea\ErrorException' => 'Exception.php',
	'Tea\LogicException' => 'Exception.php',
	'Tea\InlineComments' => 'ast/base/Comment.php',
	'Tea\BlockComment' => 'ast/base/Comment.php',
	'Tea\Docs' => 'ast/base/Docs.php',
	'Tea\ParameterDoc' => 'ast/base/Docs.php',
	'Tea\Identifiable' => 'ast/base/Identifiable.php',
	'Tea\AccessingIdentifier' => 'ast/base/Identifiable.php',
	'Tea\PlainIdentifier' => 'ast/base/Identifiable.php',
	'Tea\ConstantIdentifier' => 'ast/base/Identifiable.php',
	'Tea\VariableIdentifier' => 'ast/base/Identifiable.php',
	'Tea\ClassKindredIdentifier' => 'ast/base/Identifiable.php',
	'Tea\NamespaceIdentifier' => 'ast/base/NamespaceIdentifier.php',
	'Tea\Node' => 'ast/base/Node.php',
	'Tea\Operator' => 'ast/base/Operator.php',
	'Tea\Program' => 'ast/base/Program.php',
	'Tea\Symbol' => 'ast/base/Symbol.php',
	'Tea\TopSymbol' => 'ast/base/Symbol.php',
	'Tea\IType' => 'ast/base/Types.php',
	'Tea\ITypeTrait' => 'ast/base/Types.php',
	'Tea\BaseType' => 'ast/base/Types.php',
	'Tea\SingleGenericType' => 'ast/base/Types.php',
	'Tea\UnionType' => 'ast/base/Types.php',
	'Tea\MetaType' => 'ast/base/Types.php',
	'Tea\VoidType' => 'ast/base/Types.php',
	'Tea\NoneType' => 'ast/base/Types.php',
	'Tea\AnyType' => 'ast/base/Types.php',
	'Tea\ObjectType' => 'ast/base/Types.php',
	'Tea\StringType' => 'ast/base/Types.php',
	'Tea\FloatType' => 'ast/base/Types.php',
	'Tea\IntType' => 'ast/base/Types.php',
	'Tea\UIntType' => 'ast/base/Types.php',
	'Tea\BoolType' => 'ast/base/Types.php',
	'Tea\IterableType' => 'ast/base/Types.php',
	'Tea\ArrayType' => 'ast/base/Types.php',
	'Tea\DictType' => 'ast/base/Types.php',
	'Tea\CallableType' => 'ast/base/Types.php',
	'Tea\RegexType' => 'ast/base/Types.php',
	'Tea\XViewType' => 'ast/base/Types.php',
	'Tea\ChanType' => 'ast/base/Types.php',
	'Tea\NamespaceType' => 'ast/base/Types.php',
	'Tea\Unit' => 'ast/base/Unit.php',
	'Tea\IBlock' => 'ast/block/BaseBlock.php',
	'Tea\IBlockTrait' => 'ast/block/BaseBlock.php',
	'Tea\ControlBlock' => 'ast/block/BaseBlock.php',
	'Tea\ForInBlock' => 'ast/block/ForBlock.php',
	'Tea\ForToBlock' => 'ast/block/ForBlock.php',
	'Tea\IElseAble' => 'ast/block/IfElseBlock.php',
	'Tea\IElseBlock' => 'ast/block/IfElseBlock.php',
	'Tea\ElseTrait' => 'ast/block/IfElseBlock.php',
	'Tea\BaseIfBlock' => 'ast/block/IfElseBlock.php',
	'Tea\IfBlock' => 'ast/block/IfElseBlock.php',
	'Tea\ElseIfBlock' => 'ast/block/IfElseBlock.php',
	'Tea\ElseBlock' => 'ast/block/IfElseBlock.php',
	'Tea\SwitchBlock' => 'ast/block/SwitchBlock.php',
	'Tea\CaseBranch' => 'ast/block/SwitchBlock.php',
	'Tea\IExceptAble' => 'ast/block/TryCatchFinallyBlock.php',
	'Tea\IExceptBlock' => 'ast/block/TryCatchFinallyBlock.php',
	'Tea\TryBlock' => 'ast/block/TryCatchFinallyBlock.php',
	'Tea\CatchBlock' => 'ast/block/TryCatchFinallyBlock.php',
	'Tea\FinallyBlock' => 'ast/block/TryCatchFinallyBlock.php',
	'Tea\ExceptTrait' => 'ast/block/TryCatchFinallyBlock.php',
	'Tea\WhileBlock' => 'ast/block/WhileLoopBlock.php',
	'Tea\LoopBlock' => 'ast/block/WhileLoopBlock.php',
	'Tea\IDeclaration' => 'ast/declaration/BaseDeclaration.php',
	'Tea\ICallableDeclaration' => 'ast/declaration/BaseDeclaration.php',
	'Tea\IMemberDeclaration' => 'ast/declaration/BaseDeclaration.php',
	'Tea\IValuedDeclaration' => 'ast/declaration/BaseDeclaration.php',
	'Tea\DeclarationTrait' => 'ast/declaration/BaseDeclaration.php',
	'Tea\CallbackProtocol' => 'ast/declaration/CallbackProtocol.php',
	'Tea\ClassKindredDeclaration' => 'ast/declaration/ClassKindredDeclaration.php',
	'Tea\ClassDeclaration' => 'ast/declaration/ClassKindredDeclaration.php',
	'Tea\BuiltinTypeClassDeclaration' => 'ast/declaration/ClassKindredDeclaration.php',
	'Tea\InterfaceDeclaration' => 'ast/declaration/ClassKindredDeclaration.php',
	'Tea\TraitDeclaration' => 'ast/declaration/ClassKindredDeclaration.php',
	'Tea\ClassUseTraitsDeclaration' => 'ast/declaration/ClassUseTraitsDeclaration.php',
	'Tea\IConstantDeclaration' => 'ast/declaration/ConstantDeclaration.php',
	'Tea\ConstantDeclarationTrait' => 'ast/declaration/ConstantDeclaration.php',
	'Tea\ConstantDeclaration' => 'ast/declaration/ConstantDeclaration.php',
	'Tea\ClassConstantDeclaration' => 'ast/declaration/ConstantDeclaration.php',
	'Tea\IScopeBlock' => 'ast/declaration/FunctionDeclaration.php',
	'Tea\IFunctionDeclaration' => 'ast/declaration/FunctionDeclaration.php',
	'Tea\IScopeBlockTrait' => 'ast/declaration/FunctionDeclaration.php',
	'Tea\FunctionDeclaration' => 'ast/declaration/FunctionDeclaration.php',
	'Tea\MethodDeclaration' => 'ast/declaration/FunctionDeclaration.php',
	'Tea\MaskedDeclaration' => 'ast/declaration/MaskedDeclaration.php',
	'Tea\NamespaceDeclaration' => 'ast/declaration/NamespaceDeclaration.php',
	'Tea\IClassMemberDeclaration' => 'ast/declaration/PropertyDeclaration.php',
	'Tea\ClassMemberDeclarationTrait' => 'ast/declaration/PropertyDeclaration.php',
	'Tea\PropertyDeclaration' => 'ast/declaration/PropertyDeclaration.php',
	'Tea\IRootDeclaration' => 'ast/declaration/RootDeclaration.php',
	'Tea\RootDeclaration' => 'ast/declaration/RootDeclaration.php',
	'Tea\UseDeclaration' => 'ast/declaration/UseDeclaration.php',
	'Tea\IVariableDeclaration' => 'ast/declaration/VariableDeclaration.php',
	'Tea\BaseVariableDeclaration' => 'ast/declaration/VariableDeclaration.php',
	'Tea\VariableDeclaration' => 'ast/declaration/VariableDeclaration.php',
	'Tea\FinalVariableDeclaration' => 'ast/declaration/VariableDeclaration.php',
	'Tea\InvariantDeclaration' => 'ast/declaration/VariableDeclaration.php',
	'Tea\RuleOptions' => 'ast/declaration/VariableDeclaration.php',
	'Tea\ParameterDeclaration' => 'ast/declaration/VariableDeclaration.php',
	'Tea\SquareAccessing' => 'ast/expression/Accessing.php',
	'Tea\KeyAccessing' => 'ast/expression/Accessing.php',
	'Tea\MemberContainerTrait' => 'ast/expression/ArrayDictExpression.php',
	'Tea\ArrayExpression' => 'ast/expression/ArrayDictExpression.php',
	'Tea\DictExpression' => 'ast/expression/ArrayDictExpression.php',
	'Tea\DictMember' => 'ast/expression/ArrayDictExpression.php',
	'Tea\BaseExpression' => 'ast/expression/BaseExpression.php',
	'Tea\BaseCallExpression' => 'ast/expression/CallExpression.php',
	'Tea\PipeCallExpression' => 'ast/expression/CallExpression.php',
	'Tea\CallExpression' => 'ast/expression/CallExpression.php',
	'Tea\CallbackArgument' => 'ast/expression/CallExpression.php',
	'Tea\ExpressionList' => 'ast/expression/ExpressionList.php',
	'Tea\HTMLEscapeExpression' => 'ast/expression/HTMLEscapeExpression.php',
	'Tea\LambdaExpression' => 'ast/expression/LambdaExpression.php',
	'Tea\CoroutineBlock' => 'ast/expression/LambdaExpression.php',
	'Tea\ILiteral' => 'ast/expression/Literal.php',
	'Tea\LiteralTraitWithValue' => 'ast/expression/Literal.php',
	'Tea\NoneLiteral' => 'ast/expression/Literal.php',
	'Tea\StringLiteral' => 'ast/expression/Literal.php',
	'Tea\UnescapedStringLiteral' => 'ast/expression/Literal.php',
	'Tea\EscapedStringLiteral' => 'ast/expression/Literal.php',
	'Tea\IntegerLiteral' => 'ast/expression/Literal.php',
	'Tea\FloatLiteral' => 'ast/expression/Literal.php',
	'Tea\BooleanLiteral' => 'ast/expression/Literal.php',
	'Tea\ArrayLiteral' => 'ast/expression/Literal.php',
	'Tea\DictLiteral' => 'ast/expression/Literal.php',
	'Tea\ObjectExpression' => 'ast/expression/ObjectExpression.php',
	'Tea\ObjectMember' => 'ast/expression/ObjectExpression.php',
	'Tea\BaseOperation' => 'ast/expression/Operations.php',
	'Tea\UnaryOperation' => 'ast/expression/Operations.php',
	'Tea\MultiOperation' => 'ast/expression/Operations.php',
	'Tea\BinaryOperation' => 'ast/expression/Operations.php',
	'Tea\CastOperation' => 'ast/expression/Operations.php',
	'Tea\IsOperation' => 'ast/expression/Operations.php',
	'Tea\NoneCoalescingOperation' => 'ast/expression/Operations.php',
	'Tea\ConditionalExpression' => 'ast/expression/Operations.php',
	'Tea\PrefixOperation' => 'ast/expression/Operations.php',
	'Tea\Parentheses' => 'ast/expression/Parentheses.php',
	'Tea\RegularExpression' => 'ast/expression/RegularExpression.php',
	'Tea\RelayExpression' => 'ast/expression/RelayExpression.php',
	'Tea\StringInterpolation' => 'ast/expression/StringInterpolation.php',
	'Tea\EscapedStringInterpolation' => 'ast/expression/StringInterpolation.php',
	'Tea\UnescapedStringInterpolation' => 'ast/expression/StringInterpolation.php',
	'Tea\Ton' => 'ast/expression/Ton.php',
	'Tea\XBlock' => 'ast/expression/XBlock.php',
	'Tea\XBlockElement' => 'ast/expression/XBlock.php',
	'Tea\XBlockLeaf' => 'ast/expression/XBlock.php',
	'Tea\XBlockComment' => 'ast/expression/XBlock.php',
	'Tea\YieldExpression' => 'ast/expression/YieldExpression.php',
	'Tea\IAssignable' => 'ast/statement/Assignment.php',
	'Tea\IAssignment' => 'ast/statement/Assignment.php',
	'Tea\Assignment' => 'ast/statement/Assignment.php',
	'Tea\CompoundAssignment' => 'ast/statement/Assignment.php',
	'Tea\ArrayElementAssignment' => 'ast/statement/Assignment.php',
	'Tea\IStatement' => 'ast/statement/BaseStatement.php',
	'Tea\BaseStatement' => 'ast/statement/BaseStatement.php',
	'Tea\IBreakAble' => 'ast/statement/BreakContinueStatement.php',
	'Tea\IContinueAble' => 'ast/statement/BreakContinueStatement.php',
	'Tea\PostConditionAbleStatement' => 'ast/statement/BreakContinueStatement.php',
	'Tea\BreakStatement' => 'ast/statement/BreakContinueStatement.php',
	'Tea\ContinueStatement' => 'ast/statement/BreakContinueStatement.php',
	'Tea\EchoStatement' => 'ast/statement/EchoStatement.php',
	'Tea\ExitStatement' => 'ast/statement/ExitStatement.php',
	'Tea\NamespaceStatement' => 'ast/statement/NamespaceStatement.php',
	'Tea\NormalStatement' => 'ast/statement/NormalStatement.php',
	'Tea\ReturnStatement' => 'ast/statement/ReturnStatement.php',
	'Tea\ThrowStatement' => 'ast/statement/ThrowStatement.php',
	'Tea\UnsetStatement' => 'ast/statement/UnsetStatement.php',
	'Tea\UseStatement' => 'ast/statement/UseStatement.php',
	'Tea\VarStatement' => 'ast/statement/VarStatement.php',
	'Tea\PHPCoder' => 'coder/PHPCoder.php',
	'Tea\PHPLoaderCoder' => 'coder/PHPLoaderCoder.php',
	'Tea\TeaCoder' => 'coder/TeaCoder.php',
	'Tea\TeaHeaderCoder' => 'coder/TeaHeaderCoder.php',
	'Tea\ASTChecker' => 'factory/ASTChecker.php',
	'Tea\ASTFactory' => 'factory/ASTFactory.php',
	'Tea\ASTHelper' => 'factory/ASTHelper.php',
	'Tea\OperatorFactory' => 'factory/OperatorFactory.php',
	'Tea\PHPChecker' => 'factory/PHPChecker.php',
	'Tea\ReturnBuilder' => 'factory/ReturnBuilder.php',
	'Tea\TypeFactory' => 'factory/TypeFactory.php',
	'Tea\Dumper' => 'helper/Dumper.php',
	'Tea\FileHelper' => 'helper/FileHelper.php',
	'Tea\PHPLoaderMaker' => 'helper/PHPLoaderMaker.php',
	'Tea\PHPUnitScanner' => 'helper/PHPUnitScanner.php',
	'Tea\TeaInitializer' => 'helper/TeaInitializer.php',
	'Tea\UsageTracer' => 'helper/UsageTracer.php',
	'Tea\BaseParser' => 'parser/BaseParser.php',
	'Tea\HeaderParser' => 'parser/HeaderParser.php',
	'Tea\PHPParser' => 'parser/PHPParser.php',
	'Tea\TeaDocsTrait' => 'parser/TeaDocsTrait.php',
	'Tea\TeaHelper' => 'parser/TeaHelper.php',
	'Tea\TeaParser' => 'parser/TeaParser.php',
	'Tea\TeaSharpTrait' => 'parser/TeaSharpTrait.php',
	'Tea\TeaStringTrait' => 'parser/TeaStringTrait.php',
	'Tea\TeaTokenTrait' => 'parser/TeaTokenTrait.php',
	'Tea\TeaXBlockTrait' => 'parser/TeaXblockTrait.php'
];

spl_autoload_register(function ($class) {
	isset(__AUTOLOADS[$class]) && require __DIR__ . DIRECTORY_SEPARATOR . __AUTOLOADS[$class];
});

// end
