<div class="flex flex-col gap-2 min-w-0 max-w-full md:gap-3 md:flex-row md:items-center md:justify-between">
    <div class="flex flex-wrap items-center gap-1.5 md:gap-2">
        {!! $actions ?? '' !!}
    </div>
    <form method="GET" class="flex flex-wrap items-center gap-1.5 md:gap-2">
        @if ($filters ?? null)
            {!! $filters !!}
        @endif
        <select name="entries" aria-label="Jumlah entri per halaman" class="input w-[86px] md:w-auto py-1.5 text-[13px] shadow-sm md:py-2 md:text-body-md" onchange="this.form.submit()">
            @foreach ([10, 25, 50, 100] as $n)
                <option value="{{ $n }}" @selected(request('entries') == $n)>{{ $n }} entri</option>
            @endforeach
        </select>
        <div class="relative flex-1 md:flex-none min-w-0 max-w-full">
            <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-secondary" aria-hidden="true">search</span>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari..." aria-label="Cari..."
                class="input w-full md:w-48 pl-9 py-1.5 text-[13px] shadow-sm md:py-2 md:text-body-md">
        </div>
        @if (request()->has('kategori') || request()->has('q') || request()->has('entries'))
            <a href="{{ url()->current() }}" class="btn btn-secondary btn-sm py-1.5 md:py-2">
                <span class="material-symbols-outlined text-[16px]">refresh</span> Reset
            </a>
        @endif
    </form>
</div>
