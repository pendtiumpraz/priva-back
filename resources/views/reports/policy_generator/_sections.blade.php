{{-- Renders the canonical sections JSON. Shared by the PDF doc + HTML embed. --}}
@foreach ($sections as $node)
    @php
        $type = $node['type'] ?? null;
        $text = $node['text'] ?? '';
        $isDisclaimer = ($node['role'] ?? null) === 'legal_disclaimer';
    @endphp
    @switch($type)
        @case('heading_1')
            <h1>{{ $text }}</h1>
            @break
        @case('heading_2')
            <h2>{{ $text }}</h2>
            @break
        @case('heading_3')
            <h3>{{ $text }}</h3>
            @break
        @case('paragraph')
            <p class="{{ $isDisclaimer ? 'pg-disclaimer' : '' }}">{{ $text }}</p>
            @break
        @case('list')
            @php $items = is_array($node['items'] ?? null) ? $node['items'] : []; @endphp
            @if (count($items))
                <ul>
                    @foreach ($items as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
            @break
        @case('table')
            @php
                $headers = is_array($node['headers'] ?? null) ? $node['headers'] : [];
                $rows = is_array($node['rows'] ?? null) ? $node['rows'] : [];
            @endphp
            <table class="doc-table">
                @if (count($headers))
                    <thead><tr>
                        @foreach ($headers as $h)
                            <th>{{ $h }}</th>
                        @endforeach
                    </tr></thead>
                @endif
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @php $cells = is_array($row) ? $row : []; @endphp
                            @foreach ($cells as $c)
                                <td>{{ $c }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @break
        @default
            @if (!empty($text))
                <p class="{{ $isDisclaimer ? 'pg-disclaimer' : '' }}">{{ $text }}</p>
            @endif
    @endswitch
@endforeach
