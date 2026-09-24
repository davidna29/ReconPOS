{{--
    Filter periode (Bulan / Tahun / Rentang + opsional Supplier).
    Satu form dipakai mobile (grid 2 kolom) & desktop (flex wrap) — konsisten dgn dashboard.

    Props:
      action       : URL form GET
      reset        : URL Reset (tanpa query)
      showSupplier : bool — tampilkan dropdown supplier (pembelian)
      suppliers    : collection utk dropdown supplier
      hidden       : string HTML utk input tersembunyi (mis. sumber/q/entries penjualan)
--}}
<form method="GET" action="{{ $action }}"
    class="grid grid-cols-2 gap-2 md:flex md:flex-wrap md:items-end md:gap-2">
    {!! $hidden ?? '' !!}
    <div>
        <label class="label mb-0.5 text-[11px] md:text-body-sm">Bulan</label>
        <input type="month" name="bulan" value="{{ request('bulan') }}" class="input w-full md:w-40">
    </div>
    <div>
        <label class="label mb-0.5 text-[11px] md:text-body-sm">Tahun</label>
        <input type="number" name="tahun" min="2000" max="2099" value="{{ request('tahun') }}"
            placeholder="{{ now()->year }}" class="input w-full md:w-24">
    </div>
    <div>
        <label class="label mb-0.5 text-[11px] md:text-body-sm">Dari</label>
        <input type="date" name="from" value="{{ request('from') }}" class="input w-full">
    </div>
    <div>
        <label class="label mb-0.5 text-[11px] md:text-body-sm">Sampai</label>
        <input type="date" name="to" value="{{ request('to') }}" class="input w-full">
    </div>
    @if ($showSupplier ?? false)
        <div class="col-span-2 md:col-span-1">
            <label class="label mb-0.5 text-[11px] md:text-body-sm">Supplier</label>
            <select name="supplier" class="input w-full md:w-48">
                <option value="">Semua supplier</option>
                @foreach (($suppliers ?? collect()) as $s)
                    <option value="{{ $s->id }}" @selected((string) request('supplier') === (string) $s->id)>{{ $s->nama }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="col-span-2 flex gap-2 md:col-span-1">
        <button class="btn btn-primary btn-sm flex-1 md:flex-none">
            <span class="material-symbols-outlined text-[16px]" aria-hidden="true">filter_alt</span> Terapkan
        </button>
        <a href="{{ $reset }}" class="btn btn-secondary btn-sm flex-1 text-center md:flex-none">Reset</a>
    </div>
</form>
