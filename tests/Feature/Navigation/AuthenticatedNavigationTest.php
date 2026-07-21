<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\DB;

uses()->beforeEach(function () {
    $this->seed(PermissionRoleSeeder::class);
});

test('current named route is exposed in the authenticated navigation', function () {
    $company = Company::factory()->create();
    $admin = User::factory()->forCompany($company)->create();
    $admin->assignRole('company_admin');

    $response = $this->actingAs($admin)->get(route('empleados.index'));

    $response->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $activeLinks = (new DOMXPath($document))->query(
        '//nav//a[@href="'.route('empleados.index').'" and @aria-current="page"]',
    );

    expect($activeLinks->length)->toBe(2);
});

test('company admins cannot see global backup navigation even with direct permission', function () {
    foreach (Company::factory()->count(2)->create() as $company) {
        $admin = User::factory()->forCompany($company)->create();
        $admin->assignRole('company_admin');
        $admin->givePermissionTo('backups.run');

        $response = $this->actingAs($admin)->get(route('profile.change-password'));

        $response->assertOk();

        foreach ([
            'dashboard',
            'empleados.index',
            'archivos.index',
            'nomina.index',
            'usuarios.index',
            'jornadas.index',
            'feriados.index',
            'auditoria.index',
        ] as $routeName) {
            $response->assertSee('href="'.route($routeName).'"', escape: false);
        }

        $response
            ->assertDontSee('href="'.route('respaldos.index').'"', escape: false)
            ->assertDontSee('href="'.route('empresas.index').'"', escape: false)
            ->assertDontSee('action="'.route('current-company.update').'"', escape: false);
    }
});

test('super admin selector lists active companies with one selector query', function () {
    $activeCompany = Company::factory()->create(['name' => 'Empresa Activa Navegación']);
    $inactiveCompany = Company::factory()->inactive()->create(['name' => 'Empresa Inactiva Navegación']);
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');
    $selectorQueries = 0;

    DB::listen(function ($query) use (&$selectorQueries): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'select') && str_contains($sql, 'companies') && str_contains($sql, 'is_active')) {
            $selectorQueries++;
        }
    });

    $this->actingAs($super)
        ->get(route('profile.change-password'))
        ->assertOk()
        ->assertSee('href="'.route('empresas.index').'"', escape: false)
        ->assertSee('href="'.route('respaldos.index').'"', escape: false)
        ->assertSee('action="'.route('current-company.update').'"', escape: false)
        ->assertSee($activeCompany->name)
        ->assertDontSee($inactiveCompany->name)
        ->assertSeeInOrder([
            'id="company-disclosure-trigger"',
            'Todas las empresas',
        ], escape: false)
        ->assertSeeInOrder([
            'id="account-disclosure-panel"',
            $super->email,
            'Acceso global',
        ], escape: false);

    expect($selectorQueries)->toBe(1);
});

test('authenticated layout exposes native disclosure semantics and controls', function () {
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $response = $this->actingAs($super)
        ->get(route('profile.change-password'));

    $response
        ->assertOk()
        ->assertSee('href="#main-content"', escape: false)
        ->assertSee('aria-label="Navegación principal"', escape: false)
        ->assertSee('id="main-content" tabindex="-1"', escape: false)
        ->assertSee('id="management-disclosure-trigger"', escape: false)
        ->assertSee('aria-controls="management-disclosure-panel"', escape: false)
        ->assertSee('id="management-disclosure-panel"', escape: false)
        ->assertSee('id="company-disclosure-trigger"', escape: false)
        ->assertSee('aria-controls="company-disclosure-panel"', escape: false)
        ->assertSee('id="company-disclosure-panel"', escape: false)
        ->assertSee('id="account-disclosure-trigger"', escape: false)
        ->assertSee('aria-controls="account-disclosure-panel"', escape: false)
        ->assertSee('id="account-disclosure-panel"', escape: false)
        ->assertSee('aria-expanded="false"', escape: false)
        ->assertSee('aria-label="Abrir menú de cuenta de '.$super->email.'"', escape: false)
        ->assertSee('aria-controls="mobile-navigation-panel"', escape: false)
        ->assertSee('id="mobile-navigation-panel"', escape: false)
        ->assertSee("mobileOpen ? 'Cerrar menú principal' : 'Abrir menú principal'", escape: false)
        ->assertSee('x-ref="managementTrigger"', escape: false)
        ->assertSee('x-ref="companyTrigger"', escape: false)
        ->assertSee('x-ref="accountTrigger"', escape: false)
        ->assertDontSee('aria-haspopup="menu"', escape: false)
        ->assertDontSee('role="menu"', escape: false)
        ->assertDontSee('role="menuitem"', escape: false)
        ->assertDontSee('aria-labelledby="management-disclosure-trigger"', escape: false)
        ->assertDontSee('aria-labelledby="company-disclosure-trigger"', escape: false)
        ->assertDontSee('aria-labelledby="account-disclosure-trigger"', escape: false);

    preg_match_all('/@keydown\.escape="([^"]+)"/', $response->getContent(), $localEscapeHandlers);

    expect($localEscapeHandlers[1])->toHaveCount(3)
        ->and($response->getContent())->not->toContain('@keydown.escape.stop=')
        ->and(substr_count($response->getContent(), '@focusout='))->toBe(3)
        ->and(substr_count($response->getContent(), '$el.contains($event.relatedTarget)'))->toBe(3)
        ->and(substr_count($response->getContent(), '@keydown.escape.window='))->toBe(1);

    foreach ($localEscapeHandlers[1] as $handler) {
        expect($handler)->toContain('$event.stopPropagation()');
        $this->assertMatchesRegularExpression(
            '/^if\s*\(open\)\s*\{\s*\$event\.stopPropagation\(\)/',
            $handler,
        );
    }
});

test('account disclosure does not use a cancellable transition', function () {
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $html = $this->actingAs($super)
        ->get(route('profile.change-password'))
        ->assertOk()
        ->getContent();

    preg_match('/<div\s+[^>]*id="account-disclosure-panel"[^>]*>/', $html, $panel);

    expect($panel)->toHaveCount(1)
        ->and($panel[0])->toContain('x-show="open"')
        ->and($panel[0])->not->toContain('x-transition');
});

test('responsive navigation clears hidden disclosure state at the xl breakpoint', function () {
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $html = $this->actingAs($super)
        ->get(route('profile.change-password'))
        ->assertOk()
        ->getContent();

    preg_match_all('/@resize\.window="([^"]+)"/', $html, $resizeHandlers);
    $mobileHandlers = array_values(array_filter(
        $resizeHandlers[1],
        fn (string $handler): bool => str_contains($handler, 'mobileOpen = false'),
    ));
    $desktopHandlers = array_values(array_filter(
        $resizeHandlers[1],
        fn (string $handler): bool => str_contains($handler, 'open = false'),
    ));

    expect($mobileHandlers)->toHaveCount(1)
        ->and($mobileHandlers[0])->toContain('window.innerWidth >= 1280')
        ->and($desktopHandlers)->toHaveCount(3);

    foreach ($desktopHandlers as $handler) {
        expect($handler)->toContain('window.innerWidth < 1280');
    }
});

test('active company is identified in the selector and account context', function () {
    $activeCompany = Company::factory()->create(['name' => 'Empresa Contexto Actual']);
    $super = User::factory()->create(['company_id' => null]);
    $super->assignRole('super_admin');

    $this->withSession(['active_company_id' => $activeCompany->id])
        ->actingAs($super)
        ->get(route('profile.change-password'))
        ->assertOk()
        ->assertSeeInOrder([
            'name="company" value="'.$activeCompany->slug.'"',
            'aria-current="true"',
            $activeCompany->name,
        ], escape: false)
        ->assertSeeInOrder([
            'id="account-disclosure-panel"',
            $super->email,
            $activeCompany->name,
            'Cambiar contraseña',
        ], escape: false)
        ->assertDontSee('Acceso global');
});

test('guest login does not render authenticated navigation or query companies', function () {
    $company = Company::factory()->create(['name' => 'Empresa No Visible En Login']);
    $companyQueries = 0;

    DB::listen(function ($query) use (&$companyQueries): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'select') && str_contains($sql, 'companies')) {
            $companyQueries++;
        }
    });

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('aria-label="Navegación principal"', escape: false)
        ->assertDontSee('href="#main-content"', escape: false)
        ->assertDontSee($company->name);

    expect($companyQueries)->toBe(0);
});
