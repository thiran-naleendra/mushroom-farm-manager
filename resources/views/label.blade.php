<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Production label · {{ $farm->name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white p-6">
    <main class="mx-auto max-w-sm text-center">
        <div class="mb-6 flex justify-center gap-3 print:hidden"><a href="{{ route('farm.section', 'production') }}" class="rounded-xl border px-4 py-2">Back</a><button class="btn" x-data @click="window.print()">Print label</button></div>
        <section class="rounded-xl border border-stone-300 p-5">
            <p class="text-lg font-semibold">{{ $farm->name }}</p>
            <h1 class="mt-2 text-2xl font-bold">{{ $batch->variety }}</h1>
            <p class="muted mt-2">{{ $batch->location }} · {{ $batch->bags }} bags</p>
            <p class="muted mt-1">Started {{ $batch->started_on }}</p>
            <canvas data-qr="{{ route('production.show', $batch) }}" class="mx-auto my-4" aria-label="QR code linking to this production record"></canvas>
            <p data-qr-error hidden class="text-sm text-red-700">QR generation failed. Reload before printing.</p>
            <p class="break-all font-mono text-xs">{{ $batch->code }}</p>
        </section>
        <p class="muted mt-4 print:hidden">Scanning opens this production record. An authorized farm account must sign in.</p>
    </main>
</body>
</html>
