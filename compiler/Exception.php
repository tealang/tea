<?php
namespace Tea;

class Exception extends \Exception
{
	public $code = 1001;
}

class ErrorException extends \ErrorException
{
	public $code = 1010;
}

class LogicException extends \LogicException
{
	public $code = 1020;
}

