<x-filament-widgets::widget>
    <div wire:poll.30s style="display:flex;flex-direction:column;gap:1rem">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:1rem">
            @foreach ($kartu as $k)
                <x-filament::section>
                    <div style="font-size:.875rem;opacity:.7">{{ $k['label'] }}</div>
                    <div style="font-size:1.875rem;font-weight:600;line-height:1.2;margin-top:.25rem">{{ $k['nilai'] }}</div>
                    <div style="font-size:.8125rem;margin-top:.25rem;{{ $k['warna'] ? 'color:'.($k['warna'] === 'success' ? '#10b981' : '#f59e0b').';' : 'opacity:.7;' }}">{{ $k['ket'] }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="Proporsi referensi">
            <div style="display:flex;height:12px;border-radius:9999px;overflow:hidden;background:rgba(128,128,128,.2)">
                <div style="width:{{ $normal }}%;background:#10b981"></div>
                <div style="width:{{ $tidak }}%;background:#f59e0b"></div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:1.25rem;margin-top:.75rem;font-size:.8125rem;opacity:.8">
                <span style="display:inline-flex;align-items:center;gap:.4rem"><span style="display:inline-block;width:.75rem;height:.75rem;border-radius:.2rem;background:#10b981"></span>Normal {{ $normalLabel }}%</span>
                <span style="display:inline-flex;align-items:center;gap:.4rem"><span style="display:inline-block;width:.75rem;height:.75rem;border-radius:.2rem;background:#f59e0b"></span>Tidak normal {{ $tidakLabel }}% ({{ $polaBerbeda }} pola berbeda)</span>
            </div>

            @if (count($perJenis))
                <div style="display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1.25rem">
                    @foreach ($perJenis as $j)
                        <a href="{{ $j['url'] }}" style="display:block;padding:.75rem 1rem;border-radius:.5rem;border:1px solid rgba(128,128,128,.3);min-width:11rem">
                            <x-filament::badge :color="$j['warna']">{{ $j['kode'] }}</x-filament::badge>
                            <div style="font-size:1.25rem;font-weight:600;margin-top:.5rem">{{ $j['referensi'] }}</div>
                            <div style="font-size:.75rem;opacity:.7">referensi · {{ $j['scenario'] }} scenario</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem">
            @foreach ($tabel as $t)
                <x-filament::section :heading="$t['judul']">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                        <thead>
                            <tr style="text-align:left;opacity:.7;font-size:.8125rem">
                                <th style="padding:.5rem 0;font-weight:600">Pola</th>
                                <th style="padding:.5rem 0;font-weight:600;text-align:right">Referensi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($t['rows'] as $r)
                                <tr style="border-top:1px solid rgba(128,128,128,.25)">
                                    <td style="padding:.6rem 0;font-family:ui-monospace,Menlo,monospace;font-size:.8125rem;word-break:break-all">{{ $r['pola'] }}</td>
                                    <td style="padding:.6rem 0;text-align:right">{{ $r['jumlah'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" style="padding:1rem 0;text-align:center;opacity:.6">Tidak ada data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-filament::section>
            @endforeach
        </div>

        <div style="font-size:.75rem;opacity:.55;text-align:right">Diperbarui {{ $diperbarui }} · otomatis tiap 15 detik</div>
    </div>
</x-filament-widgets::widget>
