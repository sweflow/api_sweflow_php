#!/usr/bin/env python3
# Script para substituir a secao criar-modulo na documentacao
import sys

with open('Documentacao/index.html', 'r', encoding='utf-8') as f:
    content = f.read()

start_marker = '    <!-- PAGE: CRIAR MODULO -->'
end_marker = '    <!-- PAGE: ROTAS -->'

start_idx = content.find(start_marker)
end_idx = content.find(end_marker)

if start_idx == -1 or end_idx == -1:
    print('ERROR: markers not found')
    sys.exit(1)

before = content[:start_idx]
after = content[end_idx:]

new_section = open('scripts/new_section.html', 'r', encoding='utf-8').read()

new_content = before + new_section + after

with open('Documentacao/index.html', 'w', encoding='utf-8') as f:
    f.write(new_content)

print('Done!')
