<?php

return [
    'name'        => 'Task',
    'version'     => '1.0.0',
    'per_page'    => 20,
    'max_per_page' => 100,
    'rate_limit'  => 60,
    'cache_ttl'   => 0,
    'sortable'    => ['criado_em', 'nome'],
    'searchable'  => ['nome'],
];
