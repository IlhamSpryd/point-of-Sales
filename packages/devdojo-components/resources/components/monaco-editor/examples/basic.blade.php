<div class="flex w-full justify-center">
    <div class="w-full">
        <x-components.monaco-editor language="javascript" :height="220" :content="'function greet(name) {
    return `Hello, ${name}!`;
}

console.log(greet(\'DevDojo\'));'" />
    </div>
</div>
