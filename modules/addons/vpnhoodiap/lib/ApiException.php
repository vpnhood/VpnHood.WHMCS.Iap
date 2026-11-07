<?php

namespace WHMCS\Module\Addon\VpnHoodIap;

if (!defined('WHMCS') && !defined('VPNHOODIAP_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Exception carrying an HTTP status and a stable machine code, used to
 * short-circuit an API request with a problem+json response.
 *
 * The code — not the message — is the contract: clients branch on it, the
 * message is prose for logs and support. (Ported from vpnhoodpartnerhub.)
 */
class ApiException extends \Exception
{
    // NOT named $code: Exception already owns that property, and a promoted
    // readonly one cannot redeclare it. $previous holds what must never reach the
    // wire — WHMCS's own error text — for the logs alone (logMessage()).
    public function __construct(string $message, private readonly int $httpStatus = 400,
        private readonly ?string $errorCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** What a log keeps of a failure: the message, and the cause it hides from the client. */
    public static function logMessage(\Throwable $e): string
    {
        $previous = $e->getPrevious();
        return $previous !== null ? $e->getMessage() . ' (' . $previous->getMessage() . ')' : $e->getMessage();
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** The machine code; when a throw site did not name one, the status decides. */
    public function getErrorCode(): string
    {
        return $this->errorCode ?? self::defaultCodeFor($this->httpStatus);
    }

    public static function defaultCodeFor(int $httpStatus): string
    {
        return match ($httpStatus) {
            400     => 'bad_request',
            401     => 'unauthorized',
            403     => 'forbidden',
            404     => 'not_found',
            405     => 'method_not_allowed',
            409     => 'conflict',
            410     => 'gone',
            422     => 'unprocessable',
            429     => 'rate_limited',
            502     => 'upstream_error',
            503     => 'unavailable',
            default => $httpStatus >= 500 ? 'internal_error' : 'error',
        };
    }
}
