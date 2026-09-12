@if (session('stok_warning'))
    @php($w = session('stok_warning'))
    <div class="modal-backdrop" role="alertdialog" aria-modal="true" aria-label="Konfirmasi hapus stok minus">
        <div class="modal-card">
            <div class="mb-3 flex items-center gap-2">
                <span class="material-symbols-outlined text-danger-red">warning</span>
                <h3 class="text-headline-sm">Hapus akan membuat stok minus</h3>
            </div>
            <p class="text-body-md text-on-surface">{{ $w['pesan'] }}</p>
            @if (! empty($w['detail']))
                <ul class="mt-2 list-inside list-disc text-body-sm text-danger-red">
                    @foreach ($w['detail'] as $d)
                        <li>{{ $d }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-3 text-body-sm text-secondary">
                Melanjutkan hapus akan membuat stok jadi minus dan ditandai di Kartu Stok sebagai anomali untuk investigasi manual.
            </p>
            <div class="mt-5 flex justify-end gap-2">
                <form method="POST" action="{{ route('pembelian.destroy', $w['invoice']) }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="force" value="1">
                    <button class="btn btn-danger">
                        <span class="material-symbols-outlined text-[18px]">delete_forever</span> Lanjutkan Hapus (stok minus)
                    </button>
                </form>
                <a href="{{ url()->current() }}" class="btn btn-secondary">Batalkan</a>
            </div>
        </div>
    </div>
@endif
