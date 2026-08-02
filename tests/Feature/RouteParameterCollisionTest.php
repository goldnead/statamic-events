<?php

use Goldnead\Events\Tests\TestCase;

/*
 * This addon puts `{event}` and `{occurrence}` in its Control Panel routes —
 * both generic enough that a sibling addon could reach for them too. Using a
 * name is fine. *Binding* it is not: an implicit route-model binding claims the
 * name application-wide, so a sibling's own `{event}` route would be resolved
 * through this addon's tables, find nothing and 404 a page with nothing to do
 * with events. LeadHub's delete button died exactly that way.
 *
 * The stand-in routes come from the test bed rather than from here, because a
 * sibling registers its routes at boot and therefore ahead of Statamic's
 * `{segments?}` catch-all. A route added from inside a test body is shadowed by
 * that catch-all and answers 404 whatever the bindings do — which would make
 * this pass for the wrong reason.
 */

it('binds none of the parameter names a sibling addon might also use', function (string $name) {
    // The stand-in route does nothing but echo its own parameter. If this addon
    // ever binds the name, the binder resolves the value against a repository
    // first, finds nothing, and aborts 404 instead of echoing.
    $this->get('sibling-probe/'.$name.'/hello-world')
        ->assertOk()
        ->assertSee('hello-world');
})->with(TestCase::NAMES_A_SIBLING_MIGHT_USE);

it('resolves its own routes without a binder, from the numeric id alone', function () {
    // Which is why nothing needs binding: the controllers take an int and look
    // the row up through the model's own (brand-scoped) query.
    $route = app('router')->getRoutes()->getByName('statamic.cp.events.show');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toContain('{event}')
        ->and($route->wheres['event'] ?? null)->toBe('[0-9]+');
});
