<h2>Imbuo Library — nightly cron digest</h2>
<p>Overall: <strong>{{ $report->overall }}</strong> ({{ now()->format('Y-m-d H:i') }} UTC)</p>
<table cellpadding="6" style="border-collapse:collapse">
    <tr>
        <th align="left">Job</th>
        <th align="left">Status</th>
        <th align="left">Detail</th>
    </tr>
    @foreach ($report->jobs as $job)
        <tr>
            <td>{{ $job->label }}</td>
            <td>{{ $job->emoji() }} {{ $job->verdict }}</td>
            <td>{{ $job->summary }}</td>
        </tr>
    @endforeach
</table>
<p style="color:#888;font-size:12px">Sent by ops:nightly-digest on the imbuo-library scheduler.</p>
