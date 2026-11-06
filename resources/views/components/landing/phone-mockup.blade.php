{{-- Celular desenhado em HTML/CSS (sem captura de tela): a tela "Remédios de hoje" como o paciente a vê. Decorativo. --}}
<div {{ $attributes->class('relative mx-auto w-64 max-w-full sm:w-72') }} aria-hidden="true">
    <div class="absolute -inset-6 -z-10 rounded-[3rem] bg-gradient-to-br from-accent-200/60 via-surface-100 to-brand-100/70 blur-2xl"></div>
    <div class="rounded-[2.5rem] bg-slate-900 p-2 shadow-2xl ring-1 ring-slate-900/10">
        <div class="overflow-hidden rounded-[2rem] bg-surface-50">
            <div class="flex items-center justify-between bg-white px-5 pb-1 pt-3 text-xs font-semibold text-slate-700">
                <span>08:00</span>
                <span class="h-5 w-20 rounded-full bg-slate-900"></span>
                <span class="flex items-center gap-1"><span class="h-2.5 w-4 rounded-sm bg-slate-700"></span><span class="h-2.5 w-2.5 rounded-full bg-slate-700"></span></span>
            </div>
            <div class="border-b border-brand-100 bg-white px-4 pb-3 pt-1">
                <p class="text-xs font-medium text-slate-500">Quinta, 5 de novembro</p>
                <p class="text-lg font-semibold leading-tight text-brand-900">Remédios de hoje</p>
            </div>
            <div class="grid gap-2 p-3">
                <div class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-3">
                    <span class="text-base font-bold text-emerald-900">08:00</span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-900">Losartana 50 mg</span><span class="block text-xs text-emerald-800">Tomado às 08:04</span></span>
                    <span class="flex size-8 items-center justify-center rounded-full bg-emerald-600 text-white"><x-landing.icon name="check" class="size-4" /></span>
                </div>
                <div class="rounded-2xl border-2 border-accent-500 bg-white p-3 shadow-sm">
                    <div class="flex items-center gap-3">
                        <span class="text-base font-bold text-brand-900">12:00</span>
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-900">Metformina 850 mg</span><span class="block text-xs text-accent-700">Agora · com o almoço</span></span>
                    </div>
                    <div class="mt-3 grid gap-2">
                        <span class="flex h-10 items-center justify-center rounded-xl bg-accent-600 text-sm font-semibold text-white">Tomei</span>
                        <span class="flex h-9 items-center justify-center rounded-xl border border-slate-300 text-sm font-semibold text-slate-700">Pular</span>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3">
                    <span class="text-base font-bold text-slate-700">20:00</span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-900">Sinvastatina 20 mg</span><span class="block text-xs text-slate-500">À noite</span></span>
                    <span class="size-3 rounded-full bg-slate-300"></span>
                </div>
            </div>
            <div class="mt-1 grid grid-cols-3 border-t border-brand-100 bg-white px-2 pb-4 pt-2 text-[0.65rem] font-semibold text-slate-500">
                <span class="flex flex-col items-center gap-0.5"><x-landing.icon name="home" class="size-5" />Início</span>
                <span class="flex flex-col items-center gap-0.5"><x-landing.icon name="chat" class="size-5" />Perguntar</span>
                <span class="flex flex-col items-center gap-0.5 text-brand-800"><x-landing.icon name="pill" class="size-5" />Remédios</span>
            </div>
        </div>
    </div>
    <div class="absolute -right-4 top-20 hidden items-center gap-2 rounded-2xl bg-white px-3 py-2 text-xs font-semibold text-brand-900 shadow-lg ring-1 ring-slate-200 sm:flex">
        <span class="flex size-7 items-center justify-center rounded-full bg-accent-100 text-accent-700"><x-landing.icon name="bell" class="size-4" /></span>
        Lembrete: 12:00
    </div>
</div>
