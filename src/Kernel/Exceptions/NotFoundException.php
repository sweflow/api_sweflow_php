<?php

namespace Src\Kernel\Exceptions;

/**
 * Lançada pelo Container quando não há binding ou classe concreta para um tipo.
 * Usar instanceof é mais robusto do que string matching na mensagem de exceção.
 */
class NotFoundException extends \RuntimeException {}
