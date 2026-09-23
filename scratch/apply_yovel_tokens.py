import os

def fix_ui(filepath):
    with open(filepath, 'r') as f:
        content = f.read()

    # Replacements to standard Tailwind gray classes for consistency with Yovel design system
    replacements = {
        'text-gray-900': 'text-yovel-ink',
        'bg-gray-900': 'bg-yovel-ink',
        'border-gray-900': 'border-yovel-ink',
        'ring-gray-900': 'ring-yovel-ink',
        
        'text-gray-500': 'text-yovel-muted',
        'bg-gray-500': 'bg-yovel-muted',
        
        'text-gray-400': 'text-primary-400',
        'bg-gray-400': 'bg-primary-400',
        'placeholder-gray-400': 'placeholder-primary-400',
        
        'border-gray-200': 'border-yovel-border',
        'bg-gray-200': 'bg-yovel-border',
        'divide-gray-200': 'divide-yovel-border',
        
        'bg-gray-50/50': 'bg-yovel-bg',
        'bg-gray-50': 'bg-yovel-bg',
        
        'bg-gray-100': 'bg-yovel-surface',
        'border-gray-100': 'border-yovel-surface',
        'divide-gray-100': 'divide-yovel-surface',
        
        'text-gray-300': 'text-primary-300',
        
        'rounded-lg': 'rounded-2xl',
        'rounded-md': 'rounded-xl',
        
        'bg-white border border-yovel-border rounded-2xl shadow-sm': 'card-surface',
    }

    original_content = content
    for old, new in replacements.items():
        content = content.replace(old, new)

    if content != original_content:
        with open(filepath, 'w') as f:
            f.write(content)
        print(f"Fixed {filepath}")

files = [
    r'c:\xampp\htdocs\point-of-Sales\resources\views\livewire\inventory\ingredient-ledger.blade.php',
    r'c:\xampp\htdocs\point-of-Sales\resources\views\livewire\channel-mapping-manager.blade.php'
]

for f in files:
    if os.path.exists(f):
        fix_ui(f)
    else:
        print(f"Not found: {f}")
