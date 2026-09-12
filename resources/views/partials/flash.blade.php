@php($successes = session('success'))
@if ($successes)
    <div role="alert" data-toast class="mb-4 flex items-start gap-3 rounded-xl border border-lunas-green/40 bg-green-50 p-4 shadow-sm">
        <span class="material-symbols-outlined text-lunas-green" aria-hidden="true">check_circle</span>
        <div class="text-body-sm font-medium text-green-800">{{ $successes }}</div>
        <span class="toast-bar" aria-hidden="true"></span>
    </div>
@endif

@if (session('error'))
    <div role="alert" data-toast class="mb-4 flex items-start gap-3 rounded-xl border border-danger-red/40 bg-red-50 p-4 shadow-sm">
        <span class="material-symbols-outlined text-danger-red" aria-hidden="true">error</span>
        <div class="text-body-sm font-medium text-red-800">{{ session('error') }}</div>
        <span class="toast-bar" aria-hidden="true"></span>
    </div>
@endif

@if ($errors->any())
    <div role="alert" data-toast class="mb-4 flex items-start gap-3 rounded-xl border border-danger-red/40 bg-error-container p-4 shadow-sm">
        <span class="material-symbols-outlined text-danger-red" aria-hidden="true">warning</span>
        <ul class="list-inside list-disc text-body-sm text-on-error-container">
            @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
        <span class="toast-bar" aria-hidden="true"></span>
    </div>
@endif
