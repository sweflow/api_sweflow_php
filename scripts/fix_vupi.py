import os

skip_dirs = {'.git','vendor','node_modules','cache'}

fixes = [
    ('vupi.usVendor',            'vupiVendor'),
    ('vupi.us_migrate_core',     'vupi_migrate_core'),
    ('vupi.us_migrate_modules',  'vupi_migrate_modules'),
    ('vupi.us_plugin_migrations','vupi_plugin_migrations'),
    ('vupi.us_threat_bot_',      'vupi_threat_bot_'),
    ('vupi.us_defense_',         'vupi_defense_'),
    ('vupi.us_rl_test_',         'vupi_rl_test_'),
    ('vupi.us_audit_',           'vupi_audit_'),
    ('vupi.us_deep_',            'vupi_deep_'),
    ('vupi.us_threat_test_',     'vupi_threat_test_'),
    ("'vupi.us_'",               "'vupi_'"),
]

changed = 0
for root, dirs, files in os.walk('.'):
    dirs[:] = [d for d in dirs if d not in skip_dirs]
    for f in files:
        if f.endswith('.php') or f.endswith('.sh'):
            path = os.path.join(root, f)
            try:
                content = open(path, encoding='utf-8', errors='ignore').read()
                new = content
                for old, rep in fixes:
                    new = new.replace(old, rep)
                if new != content:
                    open(path, 'w', encoding='utf-8').write(new)
                    changed += 1
                    print('fixed:', path)
            except Exception as e:
                print('ERROR', path, e)

print('Done:', changed, 'files')
