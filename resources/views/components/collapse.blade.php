{{--
    Lipat/toggle responsif tanpa JS & tanpa duplikasi DOM (aman untuk id unik).

    Props:
      id     : id unik checkbox (default auto)
      label  : label tombol lipat (hanya tampil mobile)
      icon   : nama Material Symbol ikon
      active : bool — kondisi awal terbuka (badge "Aktif")
      tip    : teks kecil opsional di bawah label

    Pola: <input peer sr-only> + <label md:hidden> + <div hidden peer-checked:block md:block>.
    Desktop (>=768px) selalu terbuka via `md:block`; mobile tertutup kecuali diceklis.
--}}
@props(['id' => null, 'label' => 'Opsi', 'icon' => 'tune', 'active' => false, 'tip' => null])
@php($id = $id ?: 'c-'.uniqid())
<div class="min-w-0">
    <input type="checkbox" id="{{ $id }}" class="peer sr-only" @checked($active)>
    <label for="{{ $id }}"
        class="mb-2 flex cursor-pointer items-center gap-2 rounded-lg border border-outline-variant bg-white px-3 py-2 text-body-sm font-medium text-secondary transition-colors hover:bg-surface-muted md:hidden">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">{{ $icon ?? 'tune' }}</span>
        <span>{{ $label }}</span>
        @if (! empty($tip))
            <span class="hidden text-[11px] font-normal text-secondary sm:inline">{{ $tip }}</span>
        @endif
        @if ($active)
            <span class="ml-auto rounded-full bg-primary px-2 py-0.5 text-[10px] font-bold text-white">Aktif</span>
        @else
            <span class="material-symbols-outlined ml-auto text-[18px]" aria-hidden="true">expand_more</span>
        @endif
    </label>
    <div class="hidden peer-checked:block md:block">
        {{ $slot }}
    </div>
</div>
