<?php

namespace Goldnead\Events;

use Goldnead\Events\Bridges\ActivityBridge;
use Goldnead\Events\Query\Scopes\Filters;
use Goldnead\Events\Support\Ics;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Query\Scopes\Scope;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'actions' => __DIR__.'/../routes/actions.php',
    ];

    /**
     * The Control Panel bundle.
     *
     * Statamic 6 reads this from the provider property and *only* from there —
     * `extra.statamic.vite` in composer.json is not consulted, and an addon that
     * declares its build there ships a Control Panel with no addon assets at
     * all. The three values must byte-match `laravel()` in vite.config.js.
     *
     * Untyped on purpose: the parent declares it without a type, and PHP refuses
     * a child that narrows one. The parent's PHPDoc says `list<string>`, which is
     * the shorthand form registerVite() also accepts; the associative form is the
     * documented one and is what every v6 reference addon ships.
     *
     * @phpstan-ignore-next-line property.defaultValue
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../resources/dist/hot',
        'publicDirectory' => 'resources/dist',
        'input' => ['resources/js/cp.js'],
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/events.php', 'events');

        // NOT `events`: Laravel binds that key to its own event dispatcher, and
        // overwriting it takes the framework down at the next
        // `$app['events']->listen()`. Found by the test suite on the first run.
        $this->app->singleton(EventManager::class);
        $this->app->alias(EventManager::class, 'statamic-events');

        $this->app->singleton(Ics::class);

        // Registered against the resolving translator rather than in boot: the
        // nav and permission labels are built before bootAddon() runs.
        $langPath = __DIR__.'/../resources/lang';

        $this->app->resolving('translator', fn ($translator) => $translator->addNamespace('events', $langPath));

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('events', $langPath);
        }
    }

    public function bootAddon(): void
    {
        $this->bootMigrations()
            ->bootTagClasses()
            ->bootFilterScopes()
            ->bootNavigation()
            ->bootPermissions()
            ->bootActivityBridge()
            ->bootPublishables();
    }

    protected function bootMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    /**
     * The Antlers tag, registered by hand for the same reason as the filters
     * below: the parent's `$tags` path runs only after Statamic's own boot
     * sequence, which never fires in a plain console or test context, so
     * `{{ events }}` would render as nothing there. `Tags::register()` is
     * idempotent, so the parent repeating it later costs nothing.
     */
    protected function bootTagClasses(): self
    {
        Tags\Events::register();

        return $this;
    }

    /**
     * The listing filters.
     *
     * They live in `src/Query/Scopes/Filters/`, which core autoloads, so no
     * `$scopes` property is declared — an explicit list goes stale the moment
     * somebody adds a class, which surfaces as "my filter does not show up".
     *
     * They are still registered here, for the same reason the siblings record
     * for their commands: core's own scope pass runs only after Statamic's boot
     * sequence has fired, which never happens in a plain console or test
     * context, so `Scope::filters()` would return nothing there.
     * `Scope::register()` is idempotent, so core repeating it later costs
     * nothing. CpFilterScopeTest pins the full list, so a filter that stops
     * being registered fails the suite rather than going quietly missing.
     */
    protected function bootFilterScopes(): self
    {
        foreach (self::LISTING_FILTERS as $scope) {
            $scope::register();
        }

        return $this;
    }

    /** @var list<class-string<Scope>> */
    public const LISTING_FILTERS = [
        Filters\Type::class,
        Filters\Status::class,
        Filters\VisibilityFilter::class,
    ];

    protected function bootNavigation(): self
    {
        if (! config('events.cp.enabled', true)) {
            return $this;
        }

        Nav::extend(function ($nav): void {
            $nav->create(__('events::cp.nav'))
                ->section('Content')
                // A name from Statamic's own 548-icon set, not a pasted SVG:
                // only named icons pick up the CP's sizing and stroke
                // conventions, and registering the nav item is also what earns
                // the addon its breadcrumbs.
                ->icon('calendar')
                ->route('events.index')
                ->can('view events');
        });

        return $this;
    }

    /**
     * Two permissions, because there are two things to permit: reading the
     * calendar and changing it. A third for "publish" was considered and
     * dropped — it would have gated a select option inside a form that `manage
     * events` already opens, and a checkbox that controls nothing is worse than
     * no checkbox.
     */
    protected function bootPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('events', __('events::cp.nav'), function (): void {
                Permission::register('view events')
                    ->label(__('events::cp.permission_view'))
                    ->children([
                        Permission::make('manage events')
                            ->label(__('events::cp.permission_manage')),
                    ]);
            });
        });

        return $this;
    }

    /**
     * Attaches the optional activity bridge.
     *
     * Three call sites for one attachment, and the repetition is the point.
     * Statamic invokes `bootAddon()` from inside a `Statamic::booted()` callback
     * during the application's boot phase; a nested `$app->booted()` written
     * there fires *immediately* rather than later, because
     * `Application::booted()` runs its callback at once when the app is already
     * booted. So there is no single moment that is reliably late enough.
     *
     * `ActivityBridge::attach()` is idempotent and never records a negative
     * answer, which makes the retries free: the first call that finds the
     * sibling wins and the rest return at the first line.
     */
    protected function bootActivityBridge(): self
    {
        if (ActivityBridge::attach($this->app)) {
            return $this;
        }

        $this->app->booted(fn () => ActivityBridge::attach($this->app));
        Statamic::booted(fn () => ActivityBridge::attach($this->app));

        return $this;
    }

    protected function bootPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'events-migrations');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/events'),
        ], 'events-translations');

        return $this;
    }
}
