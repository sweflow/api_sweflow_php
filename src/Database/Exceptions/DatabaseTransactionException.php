<?php
namespace src\Database\Exceptions;

/**
 * Exceção para erro de transação (commit, rollback).
 */
class DatabaseTransactionException extends DatabaseException {}
