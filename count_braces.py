
with open('tenant-dashboard.php', 'r', encoding='utf-8') as f:
    content = f.read()
    open_b = content.count('{')
    close_b = content.count('}')
    print(f"Open: {open_b}, Close: {close_b}")
