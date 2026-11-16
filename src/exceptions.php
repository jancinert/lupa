<?php declare(strict_types=1);

namespace Lupa;


/**
 * An error the inspector reports to the user as it is, without a stack trace.
 */
class InspectorException extends \RuntimeException
{
}


/**
 * The configuration file is missing or invalid.
 */
class ConfigException extends InspectorException
{
}


/**
 * The application points to an environment the inspector must not touch.
 */
class GuardException extends InspectorException
{
}


/**
 * A JSON-RPC request that cannot be served; the code is the JSON-RPC error code.
 */
class ProtocolException extends \RuntimeException
{
	public const
		ParseError = -32700,
		InvalidRequest = -32600,
		MethodNotFound = -32601,
		InvalidParams = -32602,
		InternalError = -32603;
}
