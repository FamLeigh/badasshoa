<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Backing store — JSON file. Matches sister-project pattern.
function changelog_path(): string
{
    return __DIR__ . '/../content/changelog.json';
}

// Categories — keyed by type, with a label, description, and badge style.
function changelog_types(): array
{
    return [
        'feature'     => ['label' => 'New',           'badge' => 'badge--info',    'tint' => '#dbeafe', 'fg' => '#1e3a8a'],
        'improvement' => ['label' => 'Improved',      'badge' => 'badge--success', 'tint' => '#dcfce7', 'fg' => '#166534'],
        'fix'         => ['label' => 'Fixed',         'badge' => 'badge--error',   'tint' => '#fee2e2', 'fg' => '#b91c1c'],
        'design'      => ['label' => 'Design',        'badge' => 'badge--orange',  'tint' => '#ffe4d8', 'fg' => '#d44617'],
        'security'    => ['label' => 'Security',      'badge' => 'badge--warning', 'tint' => '#fbf2dc', 'fg' => '#b6822a'],
        'content'     => ['label' => 'Content',       'badge' => 'badge--navy',    'tint' => '#cfd6e4', 'fg' => '#0f1f3d'],
    ];
}

function changelog_all(?string $filterType = null): array
{
    $path = changelog_path();
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    if ($filterType !== null && $filterType !== '') {
        $data = array_values(array_filter($data, fn($e) => ($e['type'] ?? '') === $filterType));
    }
    // Newest first
    usort($data, fn($a, $b) => strcmp((string)($b['at'] ?? ''), (string)($a['at'] ?? '')));
    return $data;
}

function changelog_save(array $entries): bool
{
    $dir = dirname(changelog_path());
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents(changelog_path(), $json, LOCK_EX) !== false;
}

function changelog_add(array $data): ?array
{
    $title = trim((string)($data['title'] ?? ''));
    $type  = trim((string)($data['type']  ?? ''));
    if ($title === '' || !isset(changelog_types()[$type])) return null;

    $entry = [
        'id'          => 'cl-' . date('Ymd-His-') . substr(bin2hex(random_bytes(2)), 0, 4),
        'at'          => $data['at'] ?? date('c'),
        'type'        => $type,
        'title'       => $title,
        'description' => trim((string)($data['description'] ?? '')),
        'author'      => trim((string)($data['author'] ?? ($_SESSION['name'] ?? 'BadassHOA'))),
        'link'        => trim((string)($data['link'] ?? '')) ?: null,
    ];

    $all = changelog_all();
    array_unshift($all, $entry);
    changelog_save($all);
    return $entry;
}

function changelog_update(string $id, array $data): bool
{
    $all = changelog_all();
    $found = false;
    foreach ($all as &$e) {
        if (($e['id'] ?? '') !== $id) continue;
        $found = true;
        if (isset($data['title']))       $e['title']       = trim((string)$data['title']);
        if (isset($data['type'], changelog_types()[$data['type']])) $e['type'] = $data['type'];
        if (isset($data['description'])) $e['description'] = trim((string)$data['description']);
        if (array_key_exists('link', $data)) $e['link']    = trim((string)$data['link']) ?: null;
        if (isset($data['at']))          $e['at']          = $data['at'];
        break;
    }
    unset($e);
    return $found ? changelog_save($all) : false;
}

function changelog_delete(string $id): bool
{
    $all = changelog_all();
    $filtered = array_values(array_filter($all, fn($e) => ($e['id'] ?? '') !== $id));
    if (count($filtered) === count($all)) return false;
    return changelog_save($filtered);
}

function changelog_find(string $id): ?array
{
    foreach (changelog_all() as $e) {
        if (($e['id'] ?? '') === $id) return $e;
    }
    return null;
}
