<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-wrap items-center gap-2">
        {{ $actions ?? '' }}
    </div>
    <form method="GET" class="flex flex-wrap items-center gap-2">
        @if ($filters ?? null)
            {!! $filters !!}
        @endif
        <select name="entries" class="input w-auto" onchange="this.form.submit()">
            @foreach ([10, 25, 50, 100] as $n)
                <option value="{{ $n }}" @selected(request('entries') == $n)>{{ $n }} entri</option>
            @endforeach
        </select>
        <div class="relative">
            <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-[18px] text-secondary">search</span>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari..."
                class="input w-48 pl-9">
        </div>
        @if (request()->has('kategori') || request()->has('q') || request()->has('entries'))
            <a href="{{ url()->current() }}" class="btn btn-secondary btn-sm">
                <span class="material-symbols-outlined text-[16px]">refresh</span> Reset
            </a>
        @endif
    </form>
</div>
