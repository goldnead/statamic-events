<?php

namespace Goldnead\Events;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Events\Bridges\ActivityBridge;
use Goldnead\Events\Integrations\Insights\Cancelled;
use Goldnead\Events\Integrations\Insights\Occurrences;
use Goldnead\Events\Integrations\Insights\Published;
use Goldnead\Events\Query\Scopes\Filters;
use Goldnead\Events\Support\Ics;
use Goldnead\Events\Support\Settings;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Query\Scopes\Scope;
use Statamic\Statamic;
use Throwable;

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

    /**
     * Meldet die Einstellungen dieses Addons beim gemeinsamen Bildschirm an.
     *
     * In `boot()`, nicht in `bootAddon()`, und das ist keine Stilfrage:
     * brand-context wendet die gespeicherten Ueberschreibungen aus einem
     * `app->booted()`-Rueckruf an, damit jedes `boot()` vorher registrieren
     * konnte. `bootAddon()` laeuft selbst aus einem `app->booted()`-Rueckruf
     * (Statamics AppServiceProvider) — wer sich dort anmeldet, kommt je nach
     * Paket-Ladereihenfolge mal vor und mal nach dem Anwenden, und die
     * Einstellungen wirken auf der einen Installation und auf der anderen
     * nicht, ohne dass irgendetwas auf dem Bildschirm das sagt.
     */
    public function boot(): void
    {
        parent::boot();

        $this->app->make(SettingsRegistry::class)->register(Settings::class);
    }

    public function bootAddon(): void
    {
        $this->bootMigrations()
            ->bootTagClasses()
            ->bootActionClasses()
            ->bootFilterScopes()
            ->bootNavigation()
            ->bootPermissions()
            ->bootActivityBridge()
            ->registerInsightsMetrics()
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
    /**
     * The listing actions for a date, registered by hand for the same reason as
     * the tag above.
     *
     * They live in one registry with every other addon's and core's, and core
     * asks all of them about every item on every Control Panel listing. What
     * keeps these two off the Entries screen is their `visibleTo()`, not where
     * they are registered — see the classes.
     */
    protected function bootActionClasses(): self
    {
        Actions\CancelOccurrence::register();
        Actions\DeleteOccurrence::register();

        return $this;
    }

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

                // Nicht unter `view events` gehaengt: die Einstellungen aendern,
                // wie neue Termine starten und was der oeffentliche Feed
                // ausliefert. Das ist eine andere Frage als "darf jemand den
                // Kalender pflegen", und wer sie beantworten darf, wird
                // getrennt entschieden. Geprueft wird das Recht von
                // brand-context, nicht hier.
                Permission::register('manage events settings')
                    ->label(__('events::cp.permission_manage_settings'));
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

    /**
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can store the class name without
     * constructing anything to find out what it is called. Naming the handle
     * twice is the price of that laziness, and it is the cheaper half of the
     * trade: an install with twenty addons would otherwise build every metric
     * object of every one of them on a request that renders none.
     *
     * The handles are frozen from the moment they are registered — they end up
     * in saved dashboards and in URLs. Renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        Published::class => 'events.published',
        Occurrences::class => 'events.occurrences',
        Cancelled::class => 'events.cancelled',
    ];

    /**
     * True once the metrics are with the sibling.
     *
     * **On the instance, not the class, and that is the whole point of it.** The
     * three call sites below all belong to one provider object, which is exactly
     * the lifetime this flag should have: `MetricRegistry` is a container
     * singleton, so a registration is a fact about one application instance and
     * about nothing else.
     *
     * Static, it outlived what it was recording. Under Octane a worker keeps the
     * class between requests and rebuilds the application, so from the second
     * request on the flag said "already registered" to a registry that had just
     * been created empty — and the calendar's tiles were missing from the
     * dashboard for the entire life of that worker, with no error anywhere. It
     * records only success, so an attempt that found no sibling never stops a
     * later one from finding it. `entitlements` and `lead-magnets` keep the same
     * flag the same way.
     */
    protected bool $insightsMetricsRegistered = false;

    /**
     * Test seam. Production never forgets — an addon that can be uninstalled at
     * runtime is not a thing.
     *
     * On the instance for the same reason the flag is: a bed that reached the
     * class would reach across every application it had built, which is the
     * behaviour this file was just cured of.
     *
     * @internal
     */
    public function forgetInsightsMetrics(): void
    {
        $this->insightsMetricsRegistered = false;
    }

    /**
     * Offer the calendar figures to the analytics addon, if it is there.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a few tiles on a screen nobody has open, never a
     * calendar. The guards are three, and each one has caught a real variation
     * of "installed but not quite": the class may be absent, the container may
     * refuse to build the manager, and an older release of the sibling may have
     * the facade without this method on it.
     *
     * The metric classes name the sibling's contract in their `extends` and
     * their type hints, which is safe precisely because of the first guard: PHP
     * loads a class when something touches it, and nothing touches these unless
     * the facade exists. Hence `suggest` in composer.json rather than `require`.
     *
     * ## Why three call sites and not one
     *
     * The sibling's container bindings only exist once its own provider has
     * booted, and this one may boot first — so the attempt has to be able to
     * happen late. In every other addon of this family `$app->booted()` is that
     * "late".
     *
     * Here it is not. Statamic invokes `bootAddon()` from inside a
     * `Statamic::booted()` callback during the application's boot phase, and
     * `Application::booted()` fires a callback **immediately** when the app is
     * already booted. A registration deferred that way runs at once, before the
     * thing it was waiting for, and registers into nothing — silently, which is
     * the worst shape this failure could take. That is the same trap
     * `bootActivityBridge()` is shaped around, and it is why this method
     * attempts directly, then hangs the same attempt on both later moments.
     * Being idempotent is what makes the repetition free.
     */
    protected function registerInsightsMetrics(): self
    {
        if ($this->offerMetricsToInsights()) {
            return $this;
        }

        $this->app->booted(fn () => $this->offerMetricsToInsights());
        Statamic::booted(fn () => $this->offerMetricsToInsights());

        return $this;
    }

    /** Reports whether the metrics are now with the sibling. Never throws. */
    protected function offerMetricsToInsights(): bool
    {
        if ($this->insightsMetricsRegistered) {
            return true;
        }

        $facade = '\Goldnead\StatamicInsights\Facades\Insights';

        if (! class_exists($facade)) {
            return false;
        }

        try {
            $manager = $facade::getFacadeRoot();

            // Asked of the object, never of the facade: a facade forwards
            // through `__callStatic` and declares none of what it forwards, so
            // the probe on the facade itself is always false.
            if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                return false;
            }

            foreach (self::INSIGHTS_METRICS as $class => $handle) {
                $manager->registerMetric($class, $handle);
            }

            $this->insightsMetricsRegistered = true;

            return true;
        } catch (Throwable $e) {
            Log::warning('statamic-events: the insights metrics could not be registered.', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
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
