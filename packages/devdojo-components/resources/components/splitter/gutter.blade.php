@props(['direction' => 'horizontal'])

{{-- A server-rendered gutter placed between two panes. Split.js adopts this
     element instead of injecting its own, so when the splitter lives inside a
     Livewire component the morphed HTML has the same children the browser
     does — otherwise the morph moves the pane elements to reconcile the
     lists, and moving a pane reloads any iframe inside it. --}}
<div class="gutter gutter-{{ $direction }}" wire:ignore aria-hidden="true"></div>
