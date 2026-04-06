<?php

namespace Src\Kernel\Database;

/**
 * Enum que define qual conexão de banco um módulo deve usar.
 *
 * Como usar no provider do seu módulo:
 *
 *   public function preferredConnection(): string
 *   {
 *       return ConnectionType::CORE->value;    // banco principal (DB_*)
 *       return ConnectionType::MODULES->value; // banco de módulos (DB2_*)
 *       return ConnectionType::AUTO->value;    // core decide automaticamente
 *   }
 */
enum ConnectionType: string
{
    /** Banco principal do sistema — Auth, Usuario, Email e módulos nativos */
    case CORE    = 'core';

    /** Banco secundário para módulos externos (DB2_*) */
    case MODULES = 'modules';

    /** O core decide: módulos nativos → core, módulos externos → modules */
    case AUTO    = 'auto';
}
