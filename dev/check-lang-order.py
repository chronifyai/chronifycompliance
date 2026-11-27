#!/usr/bin/env python3
"""Lists language strings that are out of order (the Moodle code checker wants them sorted)."""
import re
import sys

path = sys.argv[1] if len(sys.argv) > 1 else 'lang/en/local_chronifycompliance.php'
keys = re.findall(r"^\$string\['([^']+)'\]", open(path).read(), re.M)
bad = [(a, b) for a, b in zip(keys, keys[1:]) if a > b]
for a, b in bad:
    print(f'"{b}" should come before "{a}"')
sys.exit(1 if bad else 0)
