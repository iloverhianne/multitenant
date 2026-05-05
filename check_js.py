
import re

with open('tenant-dashboard.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Find all <script> blocks
scripts = re.findall(r'<script>(.*?)</script>', content, re.DOTALL)

for i, script in enumerate(scripts):
    open_b = script.count('{')
    close_b = script.count('}')
    if open_b != close_b:
        print(f"Script {i} (length {len(script)}): Open={open_b}, Close={close_b}")
        # Find rough line number
        pos = content.find(script)
        line = content.count('\n', 0, pos) + 1
        print(f"Approx line: {line}")
