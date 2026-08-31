@php($successes = session('success'))
@if ($successes)
    <div class="mb-4 flex items-start gap-3 rounded-lg border border-lunas-green/40 bg-green-50 p-4">
        <span class="material-symbols-outlined text-lunas-green">check_circle</span>
        <div class="text-body-sm font-medium text-green-800">{{ $successes }}</div>
    </div>
@endif

@if (session('error'))
    <div class="mb-4 flex items-start gap-3 rounded-lg border border-danger-red/40 bg-red-50 p-4">
        <span class="material-symbols-outlined text-danger-red">error</span>
        <div class="text-body-sm font-medium text-red-800">{{ session('error') }}</div>
    </div>
@endif

@if ($errors->any())
    <div class="mb-4 flex items-start gap-3 rounded-lg border border-danger-red/40 bg-error-container p-4">
        <span class="material-symbols-outlined text-danger-red">warning</span>
        <ul class="list-inside list-disc text-body-sm text-on-error-container">
            @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif
