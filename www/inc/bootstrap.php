<?php

$action  = $_GET['action'] ?? '';
$message = '';
$error   = '';

$python_base = realpath(__DIR__ . '/../../python_tools');
if ($python_base === false) {
    throw new RuntimeException("Cartella python_tools non trovata.");
}
$python_base .= DIRECTORY_SEPARATOR;

$data_dir_raw = __DIR__ . '/../data';
if (!is_dir($data_dir_raw)) {
    mkdir($data_dir_raw, 0777, true);
}
$data_dir = realpath($data_dir_raw);
if ($data_dir === false) {
    throw new RuntimeException("Cartella data non accessibile.");
}
$data_dir .= DIRECTORY_SEPARATOR;

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function run_python($script, array $args, &$output, &$err_output) {
    global $python_base;

    // Le operazioni su 2M campioni (FFT, dilazione, convoluzione...) possono
    // richiedere decine di secondi. Rimuoviamo il limite per questa chiamata;
    // il processo Python ha il suo timeout di sistema.
    $prev_limit = ini_get('max_execution_time');
    set_time_limit(0);

    $cmd_parts = array_map('escapeshellarg', array_merge(
        ['python3', $python_base . $script],
        $args
    ));

    $cmd = implode(' ', $cmd_parts) . ' 2>&1';
    exec($cmd, $lines, $retcode);

    // Ripristina il limite originale per il resto della request
    set_time_limit((int)$prev_limit);

    $output = implode("\n", $lines);
    $err_output = ($retcode !== 0) ? $output : '';

    return $retcode === 0;
}

function data_root() {
    global $data_dir;
    return rtrim($data_dir, DIRECTORY_SEPARATOR);
}

function normalize_rel_path($rel) {
    $rel = trim((string)$rel);
    $rel = str_replace('\\', '/', $rel);
    $rel = preg_replace('~/+~', '/', $rel);
    $rel = trim($rel, '/');

    if ($rel === '') {
        return '';
    }

    $parts = explode('/', $rel);
    $safe = [];

    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '' || $p === '.') {
            continue;
        }
        if ($p === '..') {
            throw new RuntimeException("Percorso non valido.");
        }
        if (preg_match('/[<>:"|?*]/', $p)) {
            throw new RuntimeException("Nome non valido: " . $p);
        }
        $safe[] = $p;
    }

    return implode('/', $safe);
}

function rel_to_abs_data_path($rel, $must_exist = false, $allow_dir = false, array $allowed_exts = []) {
    $rel = normalize_rel_path($rel);
    $root = data_root();

    $abs = $root;
    if ($rel !== '') {
        $abs .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    if ($must_exist && !file_exists($abs)) {
        throw new RuntimeException("Percorso non trovato: " . $rel);
    }

    if ($must_exist && is_dir($abs) && !$allow_dir) {
        throw new RuntimeException("Atteso file, trovata cartella: " . $rel);
    }

    if (!$allow_dir && $rel !== '') {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($allowed_exts && !in_array($ext, $allowed_exts, true)) {
            throw new RuntimeException("Estensione non consentita: " . $rel);
        }
    }

    return $abs;
}

function sanitize_output_rel_path($rel, $default_name, array $allowed_exts) {
    $rel = trim((string)$rel);
    if ($rel === '') {
        $rel = $default_name;
    }

    $rel = normalize_rel_path($rel);

    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext === '') {
        $rel .= '.' . $allowed_exts[0];
        $ext = $allowed_exts[0];
    }

    if (!in_array($ext, $allowed_exts, true)) {
        throw new RuntimeException("Estensione output non consentita.");
    }

    return $rel;
}

function ensure_parent_dir_exists_for_rel_file($rel_file) {
    $rel_file = normalize_rel_path($rel_file);
    $parent = trim(str_replace('\\', '/', dirname($rel_file)), '/.');

    if ($parent === '') {
        return;
    }

    $abs_parent = rel_to_abs_data_path($parent, false, true);
    if (!is_dir($abs_parent) && !mkdir($abs_parent, 0777, true) && !is_dir($abs_parent)) {
        throw new RuntimeException("Impossibile creare la cartella: " . $parent);
    }
}

function rel_data_url($rel) {
    $rel = normalize_rel_path($rel);
    if ($rel === '') {
        return 'data/';
    }
    $parts = explode('/', $rel);
    $parts = array_map('rawurlencode', $parts);
    return 'data/' . implode('/', $parts);
}

function list_recursive_files_by_ext(array $exts) {
    $root = data_root();
    $out = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $ext = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) {
            continue;
        }

        $full = $item->getPathname();
        $rel = substr($full, strlen($root) + 1);
        $rel = str_replace('\\', '/', $rel);
        $out[] = $rel;
    }

    natcasesort($out);
    return array_values($out);
}

function list_recursive_dirs() {
    $root = data_root();
    $out = [''];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        if (!$item->isDir()) {
            continue;
        }

        $full = $item->getPathname();
        if ($full === $root) {
            continue;
        }

        $rel = substr($full, strlen($root) + 1);
        $rel = str_replace('\\', '/', $rel);
        $out[] = $rel;
    }

    natcasesort($out);
    return array_values(array_unique($out));
}

function list_sraw() {
    return list_recursive_files_by_ext(['sraw']);
}

function list_mp3() {
    return list_recursive_files_by_ext(['mp3']);
}

function list_manageable_files() {
    return list_recursive_files_by_ext(['sraw', 'mp3']);
}

function is_allowed_manageable_file($rel) {
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    return in_array($ext, ['sraw', 'mp3'], true);
}

function create_subdir($parent_rel, $new_name) {
    $parent_rel = normalize_rel_path($parent_rel);
    $new_name = trim((string)$new_name);

    if ($new_name === '') {
        throw new RuntimeException("Nome cartella vuoto.");
    }
    if (preg_match('~[\\\\/<>:"|?*]~', $new_name)) {
        throw new RuntimeException("Nome cartella non valido.");
    }
    if ($new_name === '.' || $new_name === '..') {
        throw new RuntimeException("Nome cartella non valido.");
    }

    $target_rel = $parent_rel === '' ? $new_name : ($parent_rel . '/' . $new_name);
    $target_abs = rel_to_abs_data_path($target_rel, false, true);

    if (file_exists($target_abs)) {
        throw new RuntimeException("Esiste già: " . $target_rel);
    }

    if (!mkdir($target_abs, 0777, true) && !is_dir($target_abs)) {
        throw new RuntimeException("Impossibile creare la cartella.");
    }

    return $target_rel;
}

function rename_data_item($old_rel, $new_name) {
    $old_rel = normalize_rel_path($old_rel);
    $old_abs = rel_to_abs_data_path($old_rel, true, true);

    $new_name = trim((string)$new_name);
    if ($new_name === '') {
        throw new RuntimeException("Nuovo nome vuoto.");
    }
    if (preg_match('~[\\\\/<>:"|?*]~', $new_name)) {
        throw new RuntimeException("Nuovo nome non valido.");
    }

    $parent_rel = trim(str_replace('\\', '/', dirname($old_rel)), '/.');
    $parent_abs = $parent_rel === '' ? data_root() : rel_to_abs_data_path($parent_rel, true, true);

    if (is_dir($old_abs)) {
        $new_rel = $parent_rel === '' ? $new_name : ($parent_rel . '/' . $new_name);
        $new_abs = $parent_abs . DIRECTORY_SEPARATOR . $new_name;

        if (file_exists($new_abs)) {
            throw new RuntimeException("Esiste già una cartella o un file con quel nome.");
        }

        if (!rename($old_abs, $new_abs)) {
            throw new RuntimeException("Rinomina cartella fallita.");
        }

        return $new_rel;
    }

    if (!is_allowed_manageable_file($old_rel)) {
        throw new RuntimeException("Si possono rinominare solo file .sraw e .mp3.");
    }

    $old_ext = strtolower(pathinfo($old_rel, PATHINFO_EXTENSION));
    $new_ext = strtolower(pathinfo($new_name, PATHINFO_EXTENSION));

    if ($new_ext === '') {
        $new_name .= '.' . $old_ext;
        $new_ext = $old_ext;
    }

    if ($new_ext !== $old_ext) {
        throw new RuntimeException("L'estensione del file deve restare ." . $old_ext);
    }

    $new_rel = $parent_rel === '' ? $new_name : ($parent_rel . '/' . $new_name);
    $new_abs = $parent_abs . DIRECTORY_SEPARATOR . $new_name;

    if (file_exists($new_abs)) {
        throw new RuntimeException("Esiste già un file con quel nome.");
    }

    if (!rename($old_abs, $new_abs)) {
        throw new RuntimeException("Rinomina file fallita.");
    }

    return $new_rel;
}

function delete_data_item($target_rel) {
    $target_rel = normalize_rel_path($target_rel);
    if ($target_rel === '') {
        throw new RuntimeException("La cartella radice non può essere cancellata.");
    }

    $target_abs = rel_to_abs_data_path($target_rel, true, true);

    if (is_dir($target_abs)) {
        $items = scandir($target_abs);
        if ($items === false) {
            throw new RuntimeException("Impossibile leggere la cartella.");
        }

        $real_items = array_values(array_diff($items, ['.', '..']));
        if (!empty($real_items)) {
            throw new RuntimeException("La cartella non è vuota.");
        }

        if (!rmdir($target_abs)) {
            throw new RuntimeException("Cancellazione cartella fallita.");
        }

        return;
    }

    if (!is_allowed_manageable_file($target_rel)) {
        throw new RuntimeException("Si possono cancellare solo file .sraw e .mp3.");
    }

    if (!unlink($target_abs)) {
        throw new RuntimeException("Cancellazione file fallita.");
    }
}

function rel_is_same_or_child_of($rel, $parent_rel) {
    $rel = normalize_rel_path($rel);
    $parent_rel = normalize_rel_path($parent_rel);

    if ($parent_rel === '') {
        return true;
    }

    return $rel === $parent_rel || str_starts_with($rel . '/', $parent_rel . '/');
}

function move_data_item($source_rel, $target_dir_rel) {
    $source_rel = normalize_rel_path($source_rel);
    $target_dir_rel = normalize_rel_path($target_dir_rel);

    if ($source_rel === '') {
        throw new RuntimeException("La cartella radice non può essere spostata.");
    }

    $source_abs = rel_to_abs_data_path($source_rel, true, true);
    $target_dir_abs = $target_dir_rel === ''
        ? data_root()
        : rel_to_abs_data_path($target_dir_rel, true, true);

    if (!is_dir($target_dir_abs)) {
        throw new RuntimeException("La destinazione non è una cartella.");
    }

    if (is_dir($source_abs)) {
        if ($target_dir_rel !== '' && rel_is_same_or_child_of($target_dir_rel, $source_rel)) {
            throw new RuntimeException("Non puoi spostare una cartella dentro sé stessa o una sua sottocartella.");
        }
    } else {
        if (!is_allowed_manageable_file($source_rel)) {
            throw new RuntimeException("Si possono spostare solo file .sraw e .mp3.");
        }
    }

    $base = basename($source_rel);
    $dest_rel = $target_dir_rel === '' ? $base : ($target_dir_rel . '/' . $base);

    if ($dest_rel === $source_rel) {
        throw new RuntimeException("Origine e destinazione coincidono.");
    }

    $dest_abs = rel_to_abs_data_path($dest_rel, false, true);

    if (file_exists($dest_abs)) {
        throw new RuntimeException("Esiste già un elemento con lo stesso nome nella cartella di destinazione.");
    }

    if (!rename($source_abs, $dest_abs)) {
        throw new RuntimeException("Spostamento fallito.");
    }

    return $dest_rel;
}