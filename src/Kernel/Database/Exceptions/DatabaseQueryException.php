<?php
namespace Src\Database\Exceptions;

use Src\Database\Exceptions\DatabaseException;

/**
 * Exceção para erro de consulta SQL (SELECT, INSERT, UPDATE, DELETE).
 */
class DatabaseQueryException extends DatabaseException {}
