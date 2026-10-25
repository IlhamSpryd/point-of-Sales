<?php

namespace DevDojo\Components\Http;

use DevDojo\Components\CodeGenerator;
use DevDojo\Components\Components;
use DevDojo\Components\Markdown;
use DevDojo\Components\Publisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;

class StudioController
{
    /**
     * The browse page: every component grouped by category.
     */
    public function index()
    {
        return view('devdojo-components::studio.index', [
            'categories' => Components::byCategory(),
        ]);
    }

    /**
     * The whole library as one Markdown document — paste-ready context for
     * AI assistants, following the llms.txt convention.
     */
    public function llms()
    {
        return response(Markdown::full(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * Render a Getting Started guide page (Introduction, Installation, …).
     */
    public function guide(string $page)
    {
        $content = "devdojo-components::studio.guides.{$page}";

        abort_unless(view()->exists($content), 404);

        return view('devdojo-components::studio.guide', [
            'categories' => Components::byCategory(),
            'page' => $page,
            'content' => $content,
        ]);
    }

    /**
     * A component's documentation + playground page.
     */
    public function show(Request $request, string $name)
    {
        abort_unless(Components::exists($name), 404);

        $component = Components::get($name);

        [$attrs, $slotValues] = $this->playgroundState($component, $request);

        $examples = collect($component['examples'])->map(function (array $example) use ($name) {
            $file = Components::sourcePath($name.'/examples/'.$example['file']);
            $source = is_file($file) ? (string) file_get_contents($file) : '';

            return $example + ['code' => trim(app(Publisher::class)->transform($source))];
        });

        return view('devdojo-components::studio.show', [
            'component' => $component,
            'categories' => Components::byCategory(),
            'attrs' => $attrs,
            'slotValues' => $slotValues,
            'initialCode' => CodeGenerator::generate(
                $name,
                $this->withoutDefaults($component, $attrs),
                $slotValues,
                published: true,
            ),
            'examples' => $examples,
        ]);
    }

    /**
     * The document rendered inside every preview iframe.
     */
    public function preview(Request $request, string $name)
    {
        abort_unless(Components::exists($name), 404);

        $component = Components::get($name);

        $requestedExample = is_string($request->query('example')) ? $request->query('example') : null;

        if ($example = $this->exampleSource($component, $requestedExample)) {
            $code = $example;
        } else {
            [$attrs, $slotValues] = $this->playgroundState($component, $request);

            // Only user-supplied values (present in the query string) are
            // untrusted and must be neutralized against Blade/PHP injection.
            // Package-authored defaults are trusted and render raw, so their
            // <x-components.*> markup compiles like an example file does.
            $userSlotKeys = array_keys((array) $request->query('slots', []));
            $renderSlots = [];
            foreach ($slotValues as $key => $value) {
                $renderSlots[$key] = in_array($key, $userSlotKeys, true)
                    ? $this->neutralizeSlot((string) $value)
                    : $value;
            }

            // e() (htmlspecialchars) does not escape { } ! ( ) @, so Blade's
            // ComponentTagCompiler would still run compileEchos/directives on a
            // raw attr value — break those tokens for query-supplied attrs only.
            $userAttrKeys = array_keys((array) $request->query('attrs', []));
            $renderAttrs = [];
            foreach ($attrs as $key => $value) {
                $renderAttrs[$key] = in_array($key, $userAttrKeys, true)
                    ? $this->neutralizeAttribute($value)
                    : $value;
            }

            $code = CodeGenerator::generate($name, $renderAttrs, $renderSlots);

            if ($container = $component['studio']['container'] ?? null) {
                $code = str_replace('{{component}}', $code, $container);
            }
        }

        try {
            $rendered = Blade::render($code);
        } catch (\Throwable $e) {
            $rendered = '<div class="w-full max-w-lg rounded-medium border border-destructive/30 bg-destructive/5 p-4">'
                .'<p class="text-sm font-semibold text-destructive">Render error</p>'
                .'<pre class="mt-2 overflow-auto whitespace-pre-wrap font-mono text-xs text-destructive/80">'.e($e->getMessage()).'</pre>'
                .'</div>';
        }

        return response()
            ->view('devdojo-components::studio.preview', [
                'rendered' => $rendered,
                'theme' => $request->query('theme') === 'dark' ? 'dark' : 'light',
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Current playground state: declared props/slots only, query values
     * overlaid on studio defaults then prop defaults. Each value is
     * constrained by the prop's declared type so scalar props can never
     * receive array-shaped query input.
     *
     * @param  array<string, mixed>  $component
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    protected function playgroundState(array $component, Request $request): array
    {
        $studioDefaults = $component['studio']['defaults'] ?? [];
        $queryAttrs = (array) $request->query('attrs', []);
        $querySlots = (array) $request->query('slots', []);

        $attrs = [];

        foreach ($component['props'] as $prop) {
            $key = $prop['name'];
            $default = $studioDefaults[$key] ?? $prop['default'] ?? '';
            $value = $queryAttrs[$key] ?? $default;
            $type = $prop['type'] ?? 'text';

            if ($type === 'boolean') {
                // Booleans arrive from the query string as "true"/"false".
                $value = $value === true || $value === 'true';
            } elseif ($type !== 'array') {
                // Scalar props: reject array-shaped input outright.
                $value = is_scalar($value) ? (string) $value : '';
            }

            $attrs[$key] = $value;
        }

        $slotValues = [];

        foreach ($component['slots'] as $slot) {
            $key = $slot['name'];
            $value = $querySlots[$key] ?? $slot['default'] ?? '';
            $slotValues[$key] = is_scalar($value) ? (string) $value : '';
        }

        if (! array_key_exists('slot', $slotValues)) {
            $fallback = $component['slots'] === [] ? '' : $component['label'];
            $value = $querySlots['slot'] ?? $fallback;
            $slotValues['slot'] = is_scalar($value) ? (string) $value : '';
        }

        return [$attrs, $slotValues];
    }

    /**
     * Drop attrs whose value equals the prop default, so generated code stays minimal.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    protected function withoutDefaults(array $component, array $attrs): array
    {
        $defaults = collect($component['props'])->keyBy('name')->map(fn ($prop) => $prop['default'] ?? '');

        return array_filter(
            $attrs,
            fn ($value, $key) => $this->stringify($value) !== $this->stringify($defaults[$key] ?? '')
                && ! ($value === false && ($defaults[$key] ?? false) === false),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Cast an attr/default value for comparison — array-type props (e.g. the
     * command palette's "items") can't be cast to string directly.
     */
    protected function stringify(mixed $value): string
    {
        return is_array($value) ? json_encode($value) : (string) $value;
    }

    /**
     * Neutralize Blade in a single user-supplied slot value for the
     * server-render path: markup passes through, but directives, echoes and PHP
     * blocks render as literal text. Applied ONLY to untrusted query-string
     * slot values — never to package-authored defaults or displayed code.
     */
    protected function neutralizeSlot(string $content): string
    {
        if (trim($content) === '') {
            return $content;
        }

        $content = str_ireplace(['@verbatim', '@endverbatim'], '', $content);

        return '@verbatim'.$content.'@endverbatim';
    }

    /**
     * Strip every Blade echo/directive trigger character ({ } @) from untrusted
     * attribute input. Blade constructs all begin with one of these, so removing
     * them makes the preview compiler unable to form any echo/directive — even
     * from 3+-brace runs or ${...} variable-variable reformation. Recurses into
     * arrays so element strings inside a :prop="[...]" bound attribute are
     * covered too. Render path only; the displayed/copyable code keeps the
     * user's literal value.
     */
    protected function neutralizeAttribute(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->neutralizeAttribute($v), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return str_replace(['{', '}', '@'], '', $value);
    }

    /**
     * The source of a declared example file, or null when not requested/known.
     *
     * @param  array<string, mixed>  $component
     */
    protected function exampleSource(array $component, ?string $requested): ?string
    {
        return $requested === null
            ? null
            : Components::exampleSource($component['name'], $requested);
    }
}
