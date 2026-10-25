<?php

namespace DevDojo\Components\Livewire;

use DevDojo\Components\Components;
use DevDojo\Components\Publisher;
use Illuminate\Support\Str;
use Livewire\Component;

class ComponentCard extends Component
{
    /**
     * The component name this card publishes (e.g. "button").
     */
    public string $name = '';

    /**
     * The human-friendly label, used in the toast confirmation.
     */
    public string $label = '';

    /**
     * Whether the component already exists in the host's views/components.
     */
    public bool $published = false;

    public function mount(string $name): void
    {
        $this->name = $name;
        $this->label = Components::get($name)['label'] ?? Str::headline($name);
        $this->published = $this->publisher()->isPublished($name);
    }

    /**
     * Publish the component (and any missing dependencies) without overwriting.
     */
    public function add(): void
    {
        $this->run(force: false);
    }

    /**
     * Re-publish the component, overwriting the existing files.
     */
    public function reAdd(): void
    {
        $this->run(force: true);
    }

    protected function run(bool $force): void
    {
        $publisher = $this->publisher();

        foreach (Components::withDependencies([$this->name]) as $dependency) {
            // Only the targeted component is overwritten; dependencies are left
            // untouched if the developer has already customized them.
            $publisher->publish($dependency, $force && $dependency === $this->name);
        }

        $this->published = true;

        $this->dispatch(
            'pop-toast',
            message: ($force ? 'Re-added ' : 'Added ').$this->label,
            type: 'success',
            description: 'Published to resources/'.trim((string) config('components.path', 'views/components'), '/')."/{$this->name}",
        );
    }

    protected function publisher(): Publisher
    {
        return app(Publisher::class);
    }

    public function render()
    {
        return view('devdojo-components::livewire.component-card');
    }
}
