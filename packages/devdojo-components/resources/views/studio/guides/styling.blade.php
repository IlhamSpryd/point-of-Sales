@php
    $themeSnippet = "@theme {\n    --color-background: var(--background);\n    --color-foreground: var(--foreground);\n    --color-card: var(--card);\n    --color-card-foreground: var(--card-foreground);\n    --color-popover: var(--popover);\n    --color-popover-foreground: var(--popover-foreground);\n    --color-primary: var(--primary);\n    --color-primary-foreground: var(--primary-foreground);\n    --color-secondary: var(--secondary);\n    --color-secondary-foreground: var(--secondary-foreground);\n    --color-muted: var(--muted);\n    --color-muted-foreground: var(--muted-foreground);\n    --color-accent: var(--accent);\n    --color-accent-foreground: var(--accent-foreground);\n    --color-destructive: var(--destructive);\n    --color-destructive-foreground: var(--destructive-foreground);\n    --color-border: var(--border);\n    --color-input: var(--input);\n    --color-ring: var(--ring);\n    --radius-small: var(--radius-small);\n    --radius-medium: var(--radius-medium);\n    --radius-large: var(--radius-large);\n}";
    $tokensSnippet = ":root {\n    --primary: oklch(0.21 0.006 285);\n    --radius-medium: 0.5rem;\n}\n\n.dark {\n    --primary: oklch(0.92 0 0);\n}";
    $overrideSnippet = "<x-button class=\"w-full rounded-full\">\n    Full width, pill shaped\n</x-button>";
@endphp

<h1>Styling</h1>
<p class="lede">You can customize the styles of your component library by modifying the theme CSS variables. Change a token once and the whole library follows — in both light and dark mode.</p>

<h2 id="theme-tokens" data-toc="Theme tokens">Theme tokens</h2>
<p>The theme lives in <code>resources/css/components.css</code> and exposes every design token to Tailwind through the <code>@theme</code> directive — this is what generates utilities like <code>bg-primary</code>, <code>text-muted-foreground</code>, and <code>rounded-medium</code>. Each token points at a plain CSS variable:</p>
<x-devdojo-components::studio.code-block class="mt-4" lang="css" :code="$themeSnippet" />

<h2 id="changing-the-values" data-toc="Changing the values">Changing the values</h2>
<p>The variables themselves get their light values from a <code>:root</code> block and their dark values from a <code>.dark</code> block in the same file. Components never hard-code a color family — they only reference semantic tokens like <code>bg-card</code>, <code>text-foreground</code>, <code>border-input</code>, or <code>bg-primary</code>. To rebrand, edit the variables:</p>
<x-devdojo-components::studio.code-block class="mt-4" lang="css" :code="$tokensSnippet" />
<p>Because everything points at these variables, that single change re-skins every button, input, card, and overlay at once.</p>

<h2 id="dark-mode-is-automatic" data-toc="Dark mode is automatic">Dark mode is automatic</h2>
<p>Tokens swap under the <code>.dark</code> class, so components adapt with no effort. You almost never need a <code>dark:</code> override — if something looks right in light mode, it will look right in dark mode too. Toggle it globally by adding or removing <code>.dark</code> on your <code>&lt;html&gt;</code> element.</p>

<h2 id="radius-scale" data-toc="Radius scale">Radius scale</h2>
<p>Roundness uses four semantic steps rather than fixed pixel values: <code>rounded-small</code>, <code>rounded-medium</code>, <code>rounded-large</code>, and <code>rounded-full</code>. Adjust the underlying <code>--radius-*</code> variables to make the whole set sharper or softer.</p>

<h2 id="overriding-a-single-instance" data-toc="Overriding a single instance">Overriding a single instance</h2>
<p>Need a one-off tweak? Pass classes directly — they merge over the component's defaults intelligently (conflicting utilities are replaced, not duplicated):</p>
<x-devdojo-components::studio.code-block class="mt-4" :code="$overrideSnippet" />
<p>For inner elements, components expose targetable slots you can style with a <code>slot:class</code> attribute — check each component's source to see what's available.</p>

<h2 id="changing-a-component-everywhere" data-toc="Changing a component everywhere">Changing a component everywhere</h2>
<p>Since components live in your app after publishing, the most powerful customization is simply editing the source in <code>resources/views/components</code>. Change a default variant, add a size, restructure the markup — it's your code now. Start from the <a href="{{ route('devdojo-components.guide', ['page' => 'introduction']) }}">Introduction</a> if you're new here.</p>
