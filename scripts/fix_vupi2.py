import os, re

files = {
    'src/CLI/PluginInspectCommand.php': [
        ('vendorVupi.us',  'vendorVupi'),
        ('$vendorVupi.us', '$vendorVupi'),
    ],
    'src/Kernel/Nucleo/ModuleLoader.php': [
        ("$vupi.us  = is_array($extra) ? ($extra['vupi.us'] ?? null) : null;",
         "$vupiExtra = is_array($extra) ? ($extra['vupi.us'] ?? null) : null;"),
        ("$providers = is_array($vupi.us) ? ($vupi.us['providers'] ?? []) : [];",
         "$providers = is_array($vupiExtra) ? ($vupiExtra['providers'] ?? []) : [];"),
    ],
}

for path, replacements in files.items():
    content = open(path, encoding='utf-8').read()
    for old, new in replacements:
        content = content.replace(old, new)
    open(path, 'w', encoding='utf-8').write(content)
    print('fixed:', path)
