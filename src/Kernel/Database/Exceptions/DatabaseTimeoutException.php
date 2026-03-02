<?php
namespace Src\Database\Exceptions;

use Src\Database\Exceptions\DatabaseException;

/**
 * Exceção para erro de timeout na conexão ou consulta ao banco de dados.
 */
class DatabaseTimeoutException extends DatabaseException {}
