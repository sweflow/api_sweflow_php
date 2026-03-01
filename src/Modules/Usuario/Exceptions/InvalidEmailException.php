<?php

namespace Modules\Usuario\Exceptions;

class InvalidEmailException extends DomainException
{
	public function __construct(string $email = '', int $code = 0, ?\Throwable $previous = null)
	{
		if (trim($email) === '') {
			$message = "E-mail não informado.";
		} else {
			$message = "E-mail inválido: $email";
		}
		parent::__construct($message, $code, $previous);
	}
}