<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the Frappe UI design layer.
 *
 * The whole product is skinned by one stylesheet loaded from the layout. If
 * that link breaks, or the shell markup the CSS targets is renamed, every page
 * silently reverts to stock Bootstrap — which looks "fine" enough that nobody
 * notices for a while. These assertions make that a build failure instead.
 */
class UiShellTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $env = [];
        foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\"'");
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host'     => $env['DB_HOST'] ?? '127.0.0.1',
            'database.connections.mysql.port'     => $env['DB_PORT'] ?? '3306',
            'database.connections.mysql.database' => $env['DB_DATABASE'] ?? 'ivfcrm',
            'database.connections.mysql.username' => $env['DB_USERNAME'] ?? 'root',
            'database.connections.mysql.password' => $env['DB_PASSWORD'] ?? '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    // -----------------------------------------------------------------
    // The stylesheet itself
    // -----------------------------------------------------------------

    public function test_the_design_system_stylesheet_exists_and_is_wellformed(): void
    {
        $path = public_path('css/frappe-ui.css');

        $this->assertFileExists($path, 'The design system stylesheet is missing.');

        $css = file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertSame(
            substr_count($stripped, '{'),
            substr_count($stripped, '}'),
            'Unbalanced braces in frappe-ui.css — the rules after the break will not apply.',
        );

        // Every var(--fui-*) must resolve to a token defined in the same file.
        preg_match_all('/(--fui-[\w-]+)\s*:/', $stripped, $defined);
        preg_match_all('/var\((--fui-[\w-]+)/', $stripped, $used);

        $missing = array_diff(array_unique($used[1]), array_unique($defined[1]));

        $this->assertEmpty($missing, 'Undefined design tokens: ' . implode(', ', $missing));
    }

    public function test_the_stylesheet_defines_both_themes(): void
    {
        $css = file_get_contents(public_path('css/frappe-ui.css'));

        $this->assertStringContainsString(':root {', $css);
        $this->assertStringContainsString('[data-bs-theme="dark"]', $css, 'Dark mode tokens are missing.');
    }

    // -----------------------------------------------------------------
    // The shell
    // -----------------------------------------------------------------

    public function test_the_layout_loads_the_design_system_after_bootstrap(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $bootstrapAt = strpos($html, 'bootstrap@5.3.3/dist/css/bootstrap.min.css');
        $designAt    = strpos($html, 'css/frappe-ui.css');

        $this->assertNotFalse($designAt, 'The design system stylesheet is not linked.');
        $this->assertNotFalse($bootstrapAt);
        $this->assertGreaterThan(
            $bootstrapAt,
            $designAt,
            'frappe-ui.css must load after Bootstrap, otherwise Bootstrap wins on source order.',
        );
    }

    public function test_the_layout_loads_the_inter_typeface(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('family=Inter', false);
    }

    /**
     * The CSS targets these hooks by id/class. Renaming one in the Blade
     * without updating the stylesheet is exactly the silent regression this
     * test is here to catch.
     */
    #[DataProvider('shellHooks')]
    public function test_the_shell_markup_the_stylesheet_targets_is_present(string $hook): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($hook, false);
    }

    public static function shellHooks(): array
    {
        return [
            'sidebar'        => ['id="sidebar"'],
            'sidebar scroll' => ['class="sidebar-scroll"'],
            'sidebar footer' => ['class="sidebar-footer"'],
            'topbar'         => ['id="topbar"'],
            'main content'   => ['id="main-content"'],
            'brand mark'     => ['class="brand-mark"'],
            'nav label'      => ['class="nav-label"'],
        ];
    }

    public function test_the_theme_is_applied_before_first_paint(): void
    {
        // A flash of the light theme on every load is the classic dark-mode bug;
        // the inline head script is what prevents it.
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->getContent();

        $headEnd = strpos($html, '</head>');
        $themeScript = strpos($html, "localStorage.getItem('theme')");

        $this->assertNotFalse($themeScript, 'The pre-paint theme script is missing.');
        $this->assertLessThan($headEnd, $themeScript, 'The theme script must run inside <head>.');
    }

    public function test_the_shared_chart_palette_is_exposed(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('window.FUI', false)
            ->assertSee('window.Apex', false);
    }

    public function test_no_view_still_carries_the_retired_brand_colour(): void
    {
        // #4361ee was the old accent. Anything still hard-coding it is a page
        // the redesign missed, and it will clash in dark mode.
        $offenders = [];

        $views = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($views as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());

            // The public landing page is customer-facing marketing with its own
            // per-page theme colour, so it is deliberately out of scope.
            if (str_starts_with($relative, 'landing' . DIRECTORY_SEPARATOR)
                || str_contains($relative, 'landing' . DIRECTORY_SEPARATOR . 'form')) {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), '#4361ee')) {
                $offenders[] = $relative;
            }
        }

        $this->assertEmpty($offenders, 'Views still using the retired brand colour: ' . implode(', ', $offenders));
    }

    // -----------------------------------------------------------------
    // DataTables
    // -----------------------------------------------------------------

    /**
     * The bug this guards: the CRM grew two table UIs. Eight older views each
     * declared their own `dom`, so they silently ignored the shared defaults
     * and rendered without a page-length control. One definition, in the
     * layout, is the whole point.
     */
    public function test_only_the_layout_defines_the_datatables_layout(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $relative => $contents) {
            if ($relative === 'layouts/app.blade.php') {
                continue;
            }

            // The dashboard widget is a deliberate, documented exception: a
            // compact preview table with no toolbar at all.
            if ($relative === 'dashboard/index.blade.php') {
                continue;
            }

            foreach (['dom:', 'pageLength:', 'lengthMenu:'] as $option) {
                if (str_contains($contents, $option)) {
                    $offenders[] = "{$relative} sets {$option}";
                }
            }
        }

        $this->assertEmpty(
            $offenders,
            "Table layout must be defined once in the app layout. Found: 
  " . implode("
  ", $offenders),
        );
    }

    public function test_the_shared_datatables_definition_is_complete(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        // The toolbar/footer wrappers the stylesheet targets.
        foreach (['dt-toolbar', 'dt-toolbar-left', 'dt-toolbar-right',
                  'dt-scroll', 'dt-footer', 'dt-footer-left', 'dt-footer-right'] as $hook) {
            $this->assertStringContainsString($hook, $layout, "The dom string is missing '{$hook}'.");
        }

        // `l` is the page-length control — the piece the old per-view dom
        // strings dropped, which is what made those tables look different.
        $this->assertMatchesRegularExpression(
            "/dt-toolbar-left'lB/",
            $layout,
            'The toolbar must contain both the length menu (l) and buttons (B).',
        );

        $this->assertStringContainsString('FUI.emptyState', $layout);
    }

    /**
     * A cell renderer runs once per row, so a <style> tag inside a row-action
     * partial was emitted once per record on screen.
     */
    public function test_no_row_action_partial_emits_a_style_tag(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $relative => $contents) {
            if (! str_contains($relative, '_actions.blade.php')) {
                continue;
            }

            if (preg_match('/<style/i', $contents)) {
                $offenders[] = $relative;
            }
        }

        $this->assertEmpty(
            $offenders,
            'Row-action partials render once per row; a <style> tag inside one is duplicated per record: '
            . implode(', ', $offenders),
        );
    }

    public function test_every_datatable_can_scroll_without_dragging_its_toolbar(): void
    {
        $missing = [];

        foreach ($this->bladeFiles() as $relative => $contents) {
            if (! str_contains($contents, 'DataTable(') || $relative === 'dashboard/index.blade.php') {
                continue;
            }

            if (! str_contains($contents, 'table-responsive')) {
                $missing[] = $relative;
            }
        }

        $this->assertEmpty($missing, 'Tables without a scroll wrapper: ' . implode(', ', $missing));

        // And the stylesheet must hand scrolling to the inner element, or the
        // search box and pagination slide sideways with a wide table.
        $css = file_get_contents(public_path('css/frappe-ui.css'));

        $this->assertStringContainsString('.dt-scroll { overflow-x: auto', $css);
        $this->assertStringContainsString('.table-responsive.dt-host { overflow: visible; }', $css);
        $this->assertStringContainsString(
            'dt-host',
            file_get_contents(resource_path('views/layouts/app.blade.php')),
            'The layout must tag scroll hosts on init.dt.',
        );
    }

    /**
     * DataTables forces `border-collapse: separate !important`, under which a
     * border on a <tr> is never painted — row dividers must be on the cells.
     */
    public function test_row_dividers_survive_the_datatables_border_collapse(): void
    {
        $css = file_get_contents(public_path('css/frappe-ui.css'));

        $this->assertMatchesRegularExpression(
            '/table\.dataTable > tbody > tr > td,\s*
\s*table\.dataTable > tbody > tr > th \{[^}]*border-bottom/',
            $css,
            'Row borders must be declared on cells, not rows, or they vanish under DataTables.',
        );
    }

    public function test_no_datatable_still_uses_a_full_cell_grid(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $relative => $contents) {
            if (str_contains($contents, 'DataTable(') && str_contains($contents, 'table-bordered')) {
                $offenders[] = $relative;
            }
        }

        $this->assertEmpty(
            $offenders,
            'These tables use a full cell grid while the rest use hairline dividers: ' . implode(', ', $offenders),
        );
    }

    /**
     * DataTables orders by column 0 unless told otherwise. On Leads that column
     * is the row checkbox, which produced a meaningless sort and left a stray
     * arrow floating in the header.
     */
    public function test_no_table_can_sort_by_a_column_that_cannot_be_sorted(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString(
            "preInit.dt",
            $layout,
            'The safety net that redirects ordering away from unsortable columns is missing.',
        );
        $this->assertStringContainsString('bSortable', $layout);

        // A non-sortable column must not advertise a control either.
        $css = file_get_contents(public_path('css/frappe-ui.css'));
        $this->assertStringContainsString('th.sorting_asc_disabled::before', $css);
        $this->assertStringContainsString('th.sorting_desc_disabled::after', $css);
    }

    /**
     * Slicing an ISO string renders "2026-06-26T16:30" — the raw T and all.
     */
    public function test_no_view_formats_a_date_by_slicing_an_iso_string(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $relative => $contents) {
            if ($relative === 'layouts/app.blade.php') {
                continue;   // documents the pattern it replaces
            }

            if (preg_match('/substring\(\s*0\s*,\s*(10|16|19)\s*\)/', $contents)) {
                $offenders[] = $relative;
            }
        }

        $this->assertEmpty(
            $offenders,
            'Use FUI.date() / FUI.dateTime() instead of slicing ISO strings: ' . implode(', ', $offenders),
        );
    }

    public function test_the_shared_date_helpers_exist(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('FUI.date = function', $layout);
        $this->assertStringContainsString('FUI.dateTime = function', $layout);
    }

    /**
     * jQuery silently strips `!important` from .css(), so this never worked.
     */
    public function test_no_view_passes_important_to_jquery_css(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $relative => $contents) {
            if (preg_match('/\.css\([^)]*!important/', $contents)) {
                $offenders[] = $relative;
            }
        }

        $this->assertEmpty(
            $offenders,
            'jQuery .css() ignores !important, so these toggles do not work: ' . implode(', ', $offenders),
        );
    }

    /**
     * The toolbar, the table body and the surrounding cards must share one
     * left edge, or every list page looks stepped.
     */
    public function test_tables_share_one_gutter_with_their_container(): void
    {
        $css = file_get_contents(public_path('css/frappe-ui.css'));

        $this->assertStringContainsString(
            'table.dataTable > tbody > tr > *:first-child { padding-left: 0; }',
            $css,
            'Outer cells must give up their padding so the wrapper owns the gutter.',
        );
        $this->assertMatchesRegularExpression(
            '/\.card > \.table-responsive > \.dataTables_wrapper,\s*
\s*\.card > \.card-body\.p-0 > \.table-responsive > \.dataTables_wrapper \{ padding: 14px; \}/',
            $css,
            'The DataTables wrapper must carry the card gutter.',
        );
    }

    /**
     * An alert usually holds prose. Making `.alert` a flex container promotes
     * every inline <a>/<strong>/<code> to its own column, which rendered the
     * Meta setup instructions as a row of stacked word-fragments and pushed the
     * page into horizontal scroll.
     */
    public function test_alerts_are_not_flex_containers_by_default(): void
    {
        $css = file_get_contents(public_path('css/frappe-ui.css'));

        preg_match('/
\.alert \{(.*?)\}/s', $css, $rule);

        $this->assertNotEmpty($rule, 'The base .alert rule is missing.');
        $this->assertStringNotContainsString(
            'display: flex',
            $rule[1],
            'A flex .alert splits prose into one column per inline element. '
            . 'Use .alert.d-flex for the icon + message row instead.',
        );

        $this->assertStringContainsString('.alert.d-flex {', $css, 'The opt-in row variant is missing.');
    }

    /**
     * The two layout alerts genuinely are icon + message rows, so they must
     * carry the class the styling now depends on.
     */
    public function test_the_session_alerts_opt_into_the_row_layout(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        foreach (['success', 'danger'] as $tone) {
            $this->assertStringContainsString(
                "alert alert-{$tone} alert-dismissible fade show d-flex",
                $layout,
                "The {$tone} session alert uses flex-grow children and needs d-flex.",
            );
        }
    }

    public function test_btn_xs_is_defined_by_the_design_system(): void
    {
        // Row actions use .btn-xs, which Bootstrap does not ship.
        $this->assertStringContainsString('.btn-xs {', file_get_contents(public_path('css/frappe-ui.css')));
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private function bladeFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(
                resource_path('views') . DIRECTORY_SEPARATOR,
                '',
                $file->getPathname(),
            );

            $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = file_get_contents($file->getPathname());
        }

        return $files;
    }

    // -----------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------

    public function test_the_login_page_uses_the_design_system(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('css/frappe-ui.css', false)
            ->assertSee('family=Inter', false)
            ->assertSee('auth-card', false);
    }

    public function test_the_demo_hint_only_shows_outside_production(): void
    {
        config(['app.env' => 'production']);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Admin@123', false);
    }
}
