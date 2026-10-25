<h1>Introduction</h1>
<p class="lede">DevDojo Components is a set of beautifully designed, accessible Blade + Alpine components for Laravel — built to be copied into your app, owned by you, and customized without limits.</p>

<h2 id="copy-don-t-install" data-toc="Copy, don't install">Copy, don't install</h2>
<p>Most component libraries are a black box: you install a package and inherit whatever markup and styles ship inside it. DevDojo Components takes the opposite approach, inspired by shadcn/ui. When you add a component, its source is <strong>published straight into your application</strong> at <code>resources/views/components</code>. From that moment it is your code — edit the markup, tweak the classes, delete what you don't need. There is no hidden magic and nothing to fight against.</p>

<h2 id="what-you-get" data-toc="What you get">What you get</h2>
<ul>
    <li><strong>Blade + Alpine, no build step.</strong> Every component is plain Blade with a little Alpine for interactivity. If you know Laravel, you already know how to work with these.</li>
    <li><strong>Automatic dark mode.</strong> Components reference semantic theme tokens, so a single <code>.dark</code> class flips the entire set — no <code>dark:</code> soup to maintain.</li>
    <li><strong>Themeable by design.</strong> Colors and radii come from CSS variables. Change a token once and every component re-skins.</li>
    <li><strong>Livewire-friendly.</strong> Inputs work with <code>wire:model</code>, and the button ships built-in loading states for <code>wire:target</code>.</li>
    <li><strong>Override anything.</strong> Pass your own classes and they merge intelligently over the defaults.</li>
</ul>

<h2 id="the-studio" data-toc="The studio">The studio</h2>
<p>This documentation site is also a workshop. Open any component to read its docs, browse curated examples, and switch to the <strong>Build</strong> tab to tune every prop and slot in a live playground — then copy paste-ready code straight into your project.</p>

<p>Ready to go? Head to <a href="{{ route('devdojo-components.guide', ['page' => 'installation']) }}">Installation</a>, or jump straight into the <a href="{{ route('devdojo-components.showcase') }}">component gallery</a>.</p>
