<?php

use App\Core\View;

View::extend('layouts.app');
View::share('title', 'API reference');

/** @var array $spec */
/** @var string $baseUrl */
/** @var array<string,string> $scopes */

$methodColors = [
    'get'    => 'badge-info',
    'post'   => 'badge-success',
    'put'    => 'badge-warning',
    'patch'  => 'badge-warning',
    'delete' => 'badge-danger',
];

// Group the paths by their first tag so the page reads like a reference.
$grouped = [];
foreach ($spec['paths'] as $path => $operations) {
    foreach ($operations as $method => $operation) {
        $tag = $operation['tags'][0] ?? 'Other';
        $grouped[$tag][] = ['path' => $path, 'method' => $method, 'operation' => $operation];
    }
}

View::startSection('content');
?>
<div class="page-head">
    <div class="page-head__text">
        <h1><?= icon('book', 'icon icon-lg') ?> API reference</h1>
        <div class="page-head__sub">
            Everything in this panel is available over REST. <?= e((string) count($spec['paths'])) ?> documented paths.
        </div>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/api/v1/openapi.json')) ?>" target="_blank" rel="noopener"><?= icon('code') ?> openapi.json</a>
        <a class="btn btn-primary" href="<?= e(url('/settings/api-keys')) ?>"><?= icon('key') ?> Manage keys</a>
    </div>
</div>

<div class="grid grid-2 mb-4">
    <div class="card">
        <div class="card__head"><?= icon('globe') ?><h3>Base URL</h3></div>
        <div class="card__body">
            <div class="input-copy">
                <input class="input mono" id="base-url" readonly value="<?= e($baseUrl) ?>">
                <button class="btn btn-icon" type="button" data-copy="#base-url" data-copy-label="Base URL"><?= icon('clipboard') ?></button>
            </div>
            <p class="small muted mt-3">
                Every response uses the same envelope:
                <span class="code-inline">{"success": true, "data": …}</span> or
                <span class="code-inline">{"success": false, "error": {"code","message"}}</span>.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card__head"><?= icon('shield') ?><h3>Authentication</h3></div>
        <div class="card__body">
            <div class="label">API key (recommended for servers)</div>
            <pre class="code-block">X-Api-Key: s3k_abcd1234_…</pre>

            <div class="label mt-3">JWT bearer token</div>
            <pre class="code-block">POST <?= e($baseUrl) ?>/auth/login
{"email":"you@example.com","password":"…"}

Authorization: Bearer &lt;access_token&gt;</pre>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card__head"><?= icon('zap') ?><h3>Quick start</h3></div>
    <div class="card__body">
        <div class="tabs">
            <button class="tab is-active" data-tab="curl" data-tab-group="qs"><?= icon('terminal') ?> cURL</button>
            <button class="tab" data-tab="php" data-tab-group="qs"><?= icon('code') ?> PHP</button>
            <button class="tab" data-tab="js" data-tab-group="qs"><?= icon('code') ?> JavaScript</button>
            <button class="tab" data-tab="java" data-tab-group="qs"><?= icon('code') ?> Java / Android</button>
        </div>

        <div data-tab-panel="curl" data-tab-group="qs">
            <pre class="code-block"># List files
curl -H "X-Api-Key: $KEY" "<?= e($baseUrl) ?>/files?per_page=10"

# Upload a file
curl -H "X-Api-Key: $KEY" -F "file=@report.pdf" -F "tags=reports,2026" \
     "<?= e($baseUrl) ?>/files/upload"

# Create a permanent share link
curl -H "X-Api-Key: $KEY" -X POST \
     -d "type=permanent" -d "password=secret" \
     "<?= e($baseUrl) ?>/files/{id}/share"

# Health probe (no auth)
curl "<?= e($baseUrl) ?>/health"</pre>
        </div>

        <div data-tab-panel="php" data-tab-group="qs" class="hidden">
            <pre class="code-block">&lt;?php
$key  = 's3k_…';
$base = '<?= e($baseUrl) ?>';

$ch = curl_init($base . '/files/upload');
curl_setopt_array($ch, [
    CURLOPT_POST           =&gt; true,
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER     =&gt; ['X-Api-Key: ' . $key],
    CURLOPT_POSTFIELDS     =&gt; [
        'file'      =&gt; new CURLFile('/path/to/report.pdf'),
        'folder_id' =&gt; 'root',
    ],
]);

$response = json_decode(curl_exec($ch), true);
echo $response['data']['uploaded'][0]['file']['download_url'];</pre>
        </div>

        <div data-tab-panel="js" data-tab-group="qs" class="hidden">
            <pre class="code-block">const base = '<?= e($baseUrl) ?>';
const key  = 's3k_…';

const form = new FormData();
form.append('file', fileInput.files[0]);

const res = await fetch(`${base}/files/upload`, {
    method: 'POST',
    headers: { 'X-Api-Key': key },
    body: form,
});

const { data } = await res.json();
console.log(data.uploaded[0].file.uuid);</pre>
        </div>

        <div data-tab-panel="java" data-tab-group="qs" class="hidden">
            <pre class="code-block">OkHttpClient client = new OkHttpClient();

RequestBody body = new MultipartBody.Builder()
    .setType(MultipartBody.FORM)
    .addFormDataPart("file", file.getName(),
        RequestBody.create(file, MediaType.parse("application/octet-stream")))
    .build();

Request request = new Request.Builder()
    .url("<?= e($baseUrl) ?>/files/upload")
    .addHeader("X-Api-Key", apiKey)
    .post(body)
    .build();

try (Response response = client.newCall(request).execute()) {
    System.out.println(response.body().string());
}</pre>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card__head"><?= icon('layers') ?><h3>Resumable uploads</h3></div>
    <div class="card__body">
        <p class="muted small">Large files are uploaded in parts. An interrupted transfer only replays the parts that are missing.</p>
        <pre class="code-block">1. POST /files/multipart/init      {"filename","total_size"}  ==>  {"upload_id","total_parts","part_size"}
2. POST /files/multipart/{id}/part  multipart body + part_number
3. GET  /files/multipart/{id}                                 ==>  {"missing_parts":[...]}   (resume here)
4. POST /files/multipart/{id}/complete {"checksum"}            ==>  the finished file
   DELETE /files/multipart/{id}                                ==>  abort and clean up</pre>
    </div>
</div>

<div class="card mb-4">
    <div class="card__head"><?= icon('key') ?><h3>Scopes</h3></div>
    <div class="card__body">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Scope</th><th>Grants</th></tr></thead>
                <tbody>
                <?php foreach ($scopes as $scope => $description): ?>
                    <tr>
                        <td class="mono nowrap"><span class="badge badge-accent"><?= e($scope) ?></span></td>
                        <td class="muted"><?= e($description) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php foreach ($grouped as $tag => $entries): ?>
    <div class="card">
        <div class="card__head"><?= icon('code') ?><h2><?= e($tag) ?></h2><span class="badge"><?= e((string) count($entries)) ?> endpoints</span></div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td class="nowrap" style="width:90px">
                                <span class="badge <?= e($methodColors[$entry['method']] ?? '') ?>"><?= e(strtoupper($entry['method'])) ?></span>
                            </td>
                            <td class="mono small nowrap"><?= e($entry['path']) ?></td>
                            <td class="muted"><?= e((string) ($entry['operation']['summary'] ?? '')) ?></td>
                            <td class="right nowrap">
                                <?php if (($entry['operation']['security'] ?? null) === []): ?>
                                    <span class="badge badge-success">public</span>
                                <?php else: ?>
                                    <span class="badge"><?= icon('lock') ?> auth</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php
View::endSection();
