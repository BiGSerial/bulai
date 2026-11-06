@props(['number', 'icon', 'title'])
<li class="relative grid min-w-0 gap-4 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
    <div class="flex items-center gap-3">
        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-accent-600 text-xl font-bold text-white shadow-sm">{{ $number }}</span>
        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-700"><x-landing.icon :name="$icon" class="size-7" /></span>
    </div>
    <h3 class="text-xl font-semibold leading-snug text-brand-900">{{ $title }}</h3>
    <p class="text-base leading-relaxed text-slate-700">{{ $slot }}</p>
</li>
