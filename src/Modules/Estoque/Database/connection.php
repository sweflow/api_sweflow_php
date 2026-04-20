<?php
// Define qual banco de dados este módulo usa.
// 'core'    → usa DB_* do .env (banco principal)
// 'modules' → usa DB2_* do .env (banco secundário)
// 'auto'    → o Kernel decide baseado na origem do módulo
//
// Este módulo está configurado para usar 'auto' porque você tem
// uma conexão de banco de dados personalizada ativa.
// O sistema priorizará automaticamente sua conexão personalizada.
return 'auto';
