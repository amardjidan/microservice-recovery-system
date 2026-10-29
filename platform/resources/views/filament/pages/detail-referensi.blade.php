@php
    $d = $this->data();
    $warna = fn ($k) => \App\Filament\Resources\AuditAnomaliResource::warna(strtoupper(explode(' ', (string) $k)[0]));
    $kode = fn ($k) => strtoupper(explode(' ', (string) $k)[0]);
    $th = 'padding:.6rem 1rem;font-weight:600;font-size:.8125rem;text-align:left;opacity:.7';
    $td = 'padding:.65rem 1rem;border-top:1px solid rgba(128,128,128,.25)';
@endphp

<x-filament-panels::page>
    @if (count($d['scenario']) === 0)
        <x-filament::section>
            <div style="text-align:center;opacity:.6;padding:1.5rem 0">Referensi ini tidak ditemukan di journal_all.</div>
        </x-filament::section>
    @endif

    @foreach ($d['scenario'] as $sc)
        @php $idx = collect($d['idx'])->where('scenario', $sc['scenario']); @endphp

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:1rem">
            <x-filament::section>
                <div style="font-size:.8125rem;opacity:.7">Scenario</div>
                <div style="font-size:1.5rem;font-weight:600">{{ $sc['scenario'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div style="font-size:.8125rem;opacity:.7">Kemunculan terbanyak / tersedikit</div>
                <div style="font-size:1.5rem;font-weight:600">{{ $sc['max_cnt'] }} / {{ $sc['min_cnt'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div style="font-size:.8125rem;opacity:.7">Idx ditemukan / seharusnya</div>
                <div style="font-size:1.5rem;font-weight:600">{{ $sc['idx_ditemukan'] }} / {{ $sc['idx_seharusnya'] }}</div>
            </x-filament::section>
            <x-filament::section>
                <div style="font-size:.8125rem;opacity:.7;margin-bottom:.4rem">Jenis</div>
                <x-filament::badge :color="$warna($sc['jenis'])">{{ $kode($sc['jenis']) }}</x-filament::badge>
            </x-filament::section>
        </div>

        <x-filament::section :heading="'Idx per scenario '.$sc['scenario']" compact>
            <div style="overflow-x:auto;margin:0 -1rem">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Idx</th>
                            <th style="{{ $th }}">Nama akun (COA)</th>
                            <th style="{{ $th }};text-align:right">Muncul</th>
                            <th style="{{ $th }};text-align:right">Seharusnya</th>
                            <th style="{{ $th }}">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($idx as $i)
                            <tr>
                                <td style="{{ $td }}">{{ $i['idx'] }}</td>
                                <td style="{{ $td }}">{{ $i['coa_name'] ?? '(tidak ada di template)' }}</td>
                                <td style="{{ $td }};text-align:right">{{ $i['muncul'] }}</td>
                                <td style="{{ $td }};text-align:right">{{ $i['max_muncul'] }}</td>
                                <td style="{{ $td }}"><x-filament::badge :color="$warna($i['status'])">{{ $kode($i['status']) }}</x-filament::badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach

    @if (count($baris))
        <x-filament::section heading="Perbaiki idx yang hilang"
            description="Nama akun, tanggal, dan bulan sumber diambil otomatis. Nominal harus Anda isi sendiri; sistem tidak menebaknya.">
            <form wire:submit="repair" style="display:flex;flex-direction:column;gap:1rem">
                @foreach ($baris as $k => $b)
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:1rem;align-items:end">
                        <div>
                            <div style="font-family:ui-monospace,Menlo,monospace;font-size:.75rem;opacity:.65">Scenario {{ $b['scenario'] }} · idx {{ $b['idx'] }}</div>
                            <div style="font-size:.875rem">{{ $b['coa'] }}</div>
                        </div>
                        <label style="font-size:.8125rem">
                            <span style="opacity:.7">Debit</span>
                            <x-filament::input.wrapper :valid="! $errors->has('baris.'.$k.'.debit')">
                                <x-filament::input type="number" step="0.01" min="0" wire:model="baris.{{ $k }}.debit" />
                            </x-filament::input.wrapper>
                        </label>
                        <label style="font-size:.8125rem">
                            <span style="opacity:.7">Kredit</span>
                            <x-filament::input.wrapper :valid="! $errors->has('baris.'.$k.'.credit')">
                                <x-filament::input type="number" step="0.01" min="0" wire:model="baris.{{ $k }}.credit" />
                            </x-filament::input.wrapper>
                        </label>
                    </div>
                    @foreach (['debit', 'credit'] as $f)
                        @error('baris.'.$k.'.'.$f)
                            <div style="font-size:.8125rem;color:rgb(var(--danger-600))">{{ $message }}</div>
                        @enderror
                    @endforeach
                @endforeach

                <label style="display:flex;align-items:flex-start;gap:.5rem;font-size:.875rem">
                    <x-filament::input.checkbox wire:model="konfirmasi" />
                    <span>Saya sudah memeriksa nominal dan setuju menambahkan {{ count($baris) }} baris ke journal_all.</span>
                </label>
                @error('konfirmasi')
                    <div style="font-size:.8125rem;color:rgb(var(--danger-600))">{{ $message }}</div>
                @enderror

                <div>
                    <x-filament::button type="submit">Tambahkan baris</x-filament::button>
                </div>
                <div style="font-size:.75rem;opacity:.6">Setiap penambahan dicatat di audit_repair_log.</div>
            </form>
        </x-filament::section>
    @endif

    @if (count($lebih))
    <x-filament::section heading="Hapus idx yang berlebih"
        description="Idx ini tidak ada di template scenario-nya. Baris yang dihapus dicatat di audit_repair_log lengkap dengan nominal, jadi bisa dipulihkan.">
        <form wire:submit="hapusLebih" style="display:flex;flex-direction:column;gap:1rem">
            @foreach ($lebih as $k => $b)
                <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem">
                    <x-filament::input.checkbox wire:model="lebih.{{ $k }}.pilih" />
                    <span>Scenario {{ $b['scenario'] }} · idx {{ $b['idx'] }} ({{ $b['muncul'] }} baris)</span>
                </label>
            @endforeach

            <label style="display:flex;align-items:flex-start;gap:.5rem;font-size:.875rem">
                <x-filament::input.checkbox wire:model="konfirmasiHapus" />
                <span>Saya sudah memeriksa dan setuju menghapus idx terpilih dari journal_all.</span>
            </label>
            @error('konfirmasiHapus')
                <div style="font-size:.8125rem;color:rgb(var(--danger-600))">{{ $message }}</div>
            @enderror

            <div>
                <x-filament::button type="submit" color="danger">Hapus idx terpilih</x-filament::button>
            </div>
        </form>
    </x-filament::section>
@endif
</x-filament-panels::page>
