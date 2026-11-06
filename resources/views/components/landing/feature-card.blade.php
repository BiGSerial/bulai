@props(['icon', 'title'])
<div {{ $attributes->class('group grid min-w-0 gap-3 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md motion-reduce:transform-none') }}>
    <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-600/10 transition group-hover:bg-accent-100 group-hover:text-accent-700"><x-landing.icon :name="$icon" class="size-7" /></span>
    <h3 class="text-xl font-semibold leading-snug text-brand-900">{{ $title }}</h3>
    <p class="text-base leading-relaxed text-slate-700">{{ $slot }}</p>
</div>
