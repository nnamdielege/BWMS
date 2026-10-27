<html><head><style>
    body { font-family: dejavusans; }
    .label { width: 90mm; height: 38mm; border: 0.4pt dashed #999; padding: 3mm; float: left; margin: 0 2mm 3mm 0; overflow: hidden; }
    .code { font-size: 26pt; font-weight: bold; }
    .path { font-size: 8pt; color: #555; }
    .bc { font-size: 7pt; color: #333; }
</style></head><body>
@foreach ($bins as $bin)
    <div class="label">
        <div class="code">{{ $bin->code }}</div>
        <div class="path">{{ $bin->path() }}{{ $bin->is_quarantine ? ' · QUARANTINE' : '' }}</div>
        <barcode code="{{ $bin->barcode }}" type="C128B" size="1.1" height="1.1" />
        <div class="bc">{{ $bin->barcode }}</div>
    </div>
@endforeach
</body></html>
