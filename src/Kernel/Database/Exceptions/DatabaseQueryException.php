<?php
namespace Src\Kernel\Database\Exceptions;

use Src\Kernel\Database\Exceptions\DatabaseException;

/**
 * Exceção para erro de consulta SQL (SELECT, INSERT, UPDATE, DELETE).
 */
class DatabaseQueryException extends DatabaseException {}
