@props(['question'])
<details {{ $attributes->class('group rounded-2xl border border-slate-200 bg-white shadow-sm open:border-brand-300 open:shadow-md') }}>
    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 text-left text-lg font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 [&::-webkit-details-marker]:hidden">
        <span class="min-w-0">{{ $question }}</span>
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 transition group-open:rotate-45 motion-reduce:transition-none"><svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg></span>
    </summary>
    <div class="border-t border-slate-100 px-5 py-4 text-base leading-relaxed text-slate-700">{{ $slot }}</div>
</details>
