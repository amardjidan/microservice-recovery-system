@php
    $hapus = $r['aksi'] === 'HAPUS';
    $warna = $hapus ? '#ef4444' : '#10b981';
    $bgBaris = $hapus ? 'rgba(239,68,68,.12)' : 'rgba(16,185,129,.12)';
    $tanda = $hapus ? '−' : '+';
    $jenis = $r['jenis'] ? explode(' ', $r['jenis'])[0] : null;
    $punyaSnapshot = $r['muncul_sebelum'] !== null;
    $angka = fn ($n) => number_format((float) $n, 2, ',', '.');
    $th = 'padding:.4rem .75rem;font-size:.75rem;opacity:.6;font-weight:600';
    $td = 'padding:.65rem .75rem;font-size:.875rem';
@endphp

<div style="display:grid;gap:1.25rem">

    {{-- Judul: scenario dan index dibuat paling besar --}}
    <div>
        <div style="font-size:.8125rem;opacity:.7">Scenario dan index yang diperbaiki</div>
        <div style="font-size:2rem;font-weight:700;line-height:1.15">
            Scenario {{ $r['scenario'] }} <span style="opacity:.4">·</span> Index {{ $r['idx'] }}
        </div>
        @if ($jenis)
            <div style="margin-top:.4rem;font-size:.875rem">
                Jenis anomali: <strong>{{ $jenis }}</strong>
            </div>
        @endif
    </div>

    {{-- Kemunculan index: sebelum → sesudah --}}
    @if ($punyaSnapshot)
    @php $lengkap = (int) $r['muncul_sesudah'] === (int) ($r['seharusnya'] ?? 0); @endphp
    <div style="padding:1rem;border-radius:.5rem;border:1px solid rgba(128,128,128,.3)">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem">
            <div style="font-size:.8125rem;opacity:.7">Jumlah baris index ini</div>
            <span style="font-size:.75rem;padding:.2rem .5rem;border-radius:.375rem;color:{{ $lengkap ? '#10b981' : '#ef4444' }};border:1px solid {{ $lengkap ? '#10b981' : '#ef4444' }}">
                {{ $lengkap ? 'Sudah lengkap' : 'Belum lengkap' }}
            </span>
        </div>
        <div style="font-size:1.75rem;font-weight:600;margin-top:.25rem">
            {{ $r['muncul_sebelum'] }}
            <span style="opacity:.4">→</span>
            <span style="color:{{ $warna }}">{{ $r['muncul_sesudah'] }}</span>
        </div>
        <div style="font-size:.8125rem;opacity:.7;margin-top:.25rem">
            Target: {{ $r['seharusnya'] ?? 0 }} baris
        </div>
    </div>
@endif

    {{-- Perubahan ala git diff --}}
    <div>
        <div style="font-size:.8125rem;opacity:.7;margin-bottom:.4rem">
            Perubahan di journal_all
            <span style="margin-left:.5rem;color:#10b981">+ ditambahkan</span>
            <span style="margin-left:.5rem;color:#ef4444">− dihapus</span>
        </div>

        <div style="border-radius:.5rem;overflow:hidden;border:1px solid rgba(128,128,128,.3)">
            <table style="width:100%;border-collapse:collapse">
                <thead>
                    <tr style="text-align:left">
                        <th style="{{ $th }};width:2rem"></th>
                        <th style="{{ $th }}">Nama akun</th>
                        <th style="{{ $th }};text-align:right">Debit</th>
                        <th style="{{ $th }};text-align:right">Kredit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:{{ $bgBaris }}">
                        <td style="{{ $td }};color:{{ $warna }};font-weight:700">{{ $tanda }}</td>
                        <td style="{{ $td }};color:{{ $warna }}">{{ $r['coa_name'] }}</td>
                        <td style="{{ $td }};text-align:right;color:{{ $warna }}">{{ $angka($r['debit']) }}</td>
                        <td style="{{ $td }};text-align:right;color:{{ $warna }}">{{ $angka($r['credit']) }}</td>
                    </tr>
                </tbody>
            </table>
            <div style="padding:.5rem .75rem;font-size:.75rem;opacity:.6;border-top:1px solid rgba(128,128,128,.3)">
                {{ $hapus ? 'Dihapus' : 'Ditambahkan' }} {{ $r['created_at'] }} oleh {{ $r['dibuat_oleh'] }}
            </div>
        </div>
    </div>

</div>