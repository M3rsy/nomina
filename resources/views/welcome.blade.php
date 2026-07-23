<x-auth.shell
    eyebrow="Acceso inicial"
    heading="Gestioná asistencia y nómina desde un solo ingreso"
    description="Ingresá con tu cuenta y accedé al panel correcto para tu rol."
>
    <main class="space-y-8" aria-labelledby="welcome-page-title">
        <section aria-labelledby="welcome-page-title">
            <header class="space-y-1">
                <p class="text-sm font-bold uppercase tracking-[0.2em] text-slate-500">Ingreso</p>
                <h2 id="welcome-page-title" class="mt-2 text-2xl font-bold text-slate-900">Entrá y accedé al panel que te toque</h2>
                <p class="mt-2 text-sm text-slate-600">Si ya tenés usuario, iniciá sesión y te redirigimos al panel para tu rol.</p>

                <nav class="mt-5 flex flex-wrap gap-3" aria-label="Acciones de acceso">
                    <ul class="flex flex-wrap gap-3">
                        <li>
                            <a
                                href="{{ route('login') }}"
                                class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                            >
                                Iniciar sesión
                            </a>
                        </li>

                        <li>
                            <a
                                href="{{ route('password.request') }}"
                                class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                            >
                                Recuperar contraseña
                            </a>
                        </li>
                    </ul>
                </nav>
            </header>
        </section>

        <section aria-labelledby="modules-title">
            <h2 id="modules-title" class="text-lg font-semibold text-slate-900">Módulos disponibles</h2>
            <p class="mt-1 text-sm text-slate-600">Qué podés gestionar desde la plataforma una vez autenticado.</p>

            <ul class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <li>
                    <article class="rounded-xl border border-slate-200 bg-white p-4">
                        <h3 class="text-base font-semibold text-slate-900">Asistencia y jornadas</h3>
                        <p class="mt-2 text-sm text-slate-600">Centralizá marcaciones, excepciones y reglas por franja horaria.</p>
                        <a
                            href="{{ route('login') }}"
                            class="mt-4 inline-flex text-sm font-semibold text-indigo-700"
                            aria-label="Ingresá para acceder a Asistencia y jornadas"
                        >
                            Acceder
                        </a>
                    </article>
                </li>

                <li>
                    <article class="rounded-xl border border-slate-200 bg-white p-4">
                        <h3 class="text-base font-semibold text-slate-900">Nómina</h3>
                        <p class="mt-2 text-sm text-slate-600">Cerrá periodos y exportá el cálculo con trazabilidad.</p>
                        <a
                            href="{{ route('login') }}"
                            class="mt-4 inline-flex text-sm font-semibold text-indigo-700"
                            aria-label="Ingresá para acceder a Nómina"
                        >
                            Acceder
                        </a>
                    </article>
                </li>

                <li>
                    <article class="rounded-xl border border-slate-200 bg-white p-4">
                        <h3 class="text-base font-semibold text-slate-900">Feriados</h3>
                        <p class="mt-2 text-sm text-slate-600">Gestioná días no laborables y aplica efectos por empresa.</p>
                        <a
                            href="{{ route('login') }}"
                            class="mt-4 inline-flex text-sm font-semibold text-indigo-700"
                            aria-label="Ingresá para acceder a Feriados"
                        >
                            Acceder
                        </a>
                    </article>
                </li>

                <li>
                    <article class="rounded-xl border border-slate-200 bg-white p-4">
                        <h3 class="text-base font-semibold text-slate-900">Respaldos</h3>
                        <p class="mt-2 text-sm text-slate-600">Protegé y recuperá información crítica de operación.</p>
                        <a
                            href="{{ route('login') }}"
                            class="mt-4 inline-flex text-sm font-semibold text-indigo-700"
                            aria-label="Ingresá para acceder a Respaldos"
                        >
                            Acceder
                        </a>
                    </article>
                </li>

                <li>
                    <article class="rounded-xl border border-slate-200 bg-white p-4">
                        <h3 class="text-base font-semibold text-slate-900">Usuarios/Empresa</h3>
                        <p class="mt-2 text-sm text-slate-600">Administrá acceso, roles y datos base de tu compañía.</p>
                        <a
                            href="{{ route('login') }}"
                            class="mt-4 inline-flex text-sm font-semibold text-indigo-700"
                            aria-label="Ingresá para acceder a Usuarios y Empresa"
                        >
                            Acceder
                        </a>
                    </article>
                </li>
            </ul>
        </section>

        <section aria-labelledby="system-health-title">
            <h2 id="system-health-title" class="text-lg font-semibold text-slate-900">Estado del sistema</h2>
            <p class="mt-1 text-sm text-slate-600">Indicadores estáticos para detectar si los servicios clave están disponibles.</p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <dl class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <dt class="text-sm font-semibold text-emerald-800">Base de datos</dt>
                    <dd class="mt-1 text-sm text-emerald-700">Verificación estática: <span class="font-semibold">verificado</span></dd>
                </dl>

                <dl class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                    <dt class="text-sm font-semibold text-blue-800">Storage</dt>
                    <dd class="mt-1 text-sm text-blue-700">Verificación estática: <span class="font-semibold">verificado</span></dd>
                </dl>

                <dl class="rounded-xl border border-violet-200 bg-violet-50 p-4">
                    <dt class="text-sm font-semibold text-violet-800">Cache</dt>
                    <dd class="mt-1 text-sm text-violet-700">Verificación estática: <span class="font-semibold">verificado</span></dd>
                </dl>
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                <a
                    href="{{ route('health') }}"
                    class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                >
                    Estado /health
                </a>

                <a
                    href="mailto:soporte@cfv.com.ar"
                    class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                >
                    Soporte
                </a>
            </div>
        </section>
    </main>
</x-auth.shell>
