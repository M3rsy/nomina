<x-auth.shell
    eyebrow="Asistencia y nómina operativa"
    heading="Sistema de planilla para gestionar tu operación"
    description="Monitorea asistencia, jornadas, nómina y respaldos desde una sola entrada para que tu equipo avance sin fricción."
>
    <div class="space-y-8">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <p class="text-sm font-bold uppercase tracking-[0.2em] text-slate-500">Acción principal</p>
            <h2 class="mt-2 text-2xl font-bold text-slate-900">Entrá y empezá con el módulo que necesitás</h2>
            <p class="mt-2 text-sm text-slate-600">Todo lo clave para operar nómina está a un click de tu sesión.</p>

            <div class="mt-5 flex flex-wrap gap-3">
                <a
                    href="{{ route('login') }}"
                    class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700"
                    aria-label="Ingresar al sistema para empezar"
                >
                    Iniciar sesión
                </a>

                <a
                    href="{{ route('login') }}"
                    class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                    aria-label="Ir al login para acceder a tu cuenta"
                >
                    Volver al login
                </a>
            </div>
        </div>

        <section aria-labelledby="modules-title">
            <h2 id="modules-title" class="text-lg font-semibold text-slate-900">Módulos y pasos de valor</h2>
            <p class="mt-1 text-sm text-slate-600">Flujos de trabajo listos para empezar tu operación.</p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-base font-semibold text-slate-900">Asistencia y jornadas</h3>
                    <p class="mt-2 text-sm text-slate-600">Centraliza marcaciones, excepciones y reglas por franja horaria.</p>
                    <a href="{{ route('login') }}" class="mt-4 inline-flex text-sm font-semibold text-indigo-700" aria-label="Acceder a asistencia y jornadas">Entrar</a>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-base font-semibold text-slate-900">Nómina</h3>
                    <p class="mt-2 text-sm text-slate-600">Cerrá periodos y exportá el cálculo con trazabilidad.</p>
                    <a href="{{ route('login') }}" class="mt-4 inline-flex text-sm font-semibold text-indigo-700" aria-label="Acceder a nómina">Entrar</a>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-base font-semibold text-slate-900">Feriados</h3>
                    <p class="mt-2 text-sm text-slate-600">Gestioná días no laborables y aplica efectos por empresa.</p>
                    <a href="{{ route('login') }}" class="mt-4 inline-flex text-sm font-semibold text-indigo-700" aria-label="Acceder a feriados">Entrar</a>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-base font-semibold text-slate-900">Respaldos</h3>
                    <p class="mt-2 text-sm text-slate-600">Protegé y recuperá información crítica de operación.</p>
                    <a href="{{ route('login') }}" class="mt-4 inline-flex text-sm font-semibold text-indigo-700" aria-label="Acceder a respaldos">Entrar</a>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-4">
                    <h3 class="text-base font-semibold text-slate-900">Usuarios/Empresa</h3>
                    <p class="mt-2 text-sm text-slate-600">Administrá acceso, roles y datos base de tu compañía.</p>
                    <a href="{{ route('login') }}" class="mt-4 inline-flex text-sm font-semibold text-indigo-700" aria-label="Acceder a usuarios y empresa">Entrar</a>
                </article>
            </div>
        </section>

        <section aria-labelledby="system-health-title">
            <h2 id="system-health-title" class="text-lg font-semibold text-slate-900">Estado del sistema</h2>

            <dl class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <dt class="text-sm font-semibold text-emerald-800">Base de datos</dt>
                    <dd class="mt-1 text-sm text-emerald-700">Verificación estática: <span class="font-semibold">verificado</span></dd>
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                    <dt class="text-sm font-semibold text-blue-800">Storage</dt>
                    <dd class="mt-1 text-sm text-blue-700">Verificación estática: <span class="font-semibold">verificado</span></dd>
                </div>

                <div class="rounded-xl border border-violet-200 bg-violet-50 p-4">
                    <dt class="text-sm font-semibold text-violet-800">Cache</dt>
                    <dd class="mt-1 text-sm text-violet-700">Verificación estática: <span class="font-semibold">verificado</span></dd>
                </div>
            </dl>

            <div class="mt-4 flex flex-wrap gap-3">
                <a
                    href="{{ route('health') }}"
                    class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                    aria-label="Abrir estado público del sistema"
                >
                    Estado /health
                </a>

                <a
                    href="mailto:soporte@cfv.com.ar"
                    class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                    aria-label="Contactar soporte técnico"
                >
                    Soporte
                </a>
            </div>
        </section>
    </div>
</x-auth.shell>
