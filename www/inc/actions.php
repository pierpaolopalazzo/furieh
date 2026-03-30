<?php

if ($action === 'save_sraw' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: text/plain; charset=UTF-8');

    try {
        $rel_out = sanitize_output_rel_path($_POST['output_sraw'] ?? 'signal.sraw', 'signal.sraw', ['sraw']);
        $content = (string)($_POST['content'] ?? '');

        if ($content === '') {
            http_response_code(400);
            echo "__SRAW_SAVE_ERR__\nContenuto vuoto.";
            exit;
        }

        ensure_parent_dir_exists_for_rel_file($rel_out);
        $target_abs = rel_to_abs_data_path($rel_out, false, false, ['sraw']);

        $ok = @file_put_contents($target_abs, $content, LOCK_EX);
        if ($ok === false) {
            http_response_code(500);
            echo "__SRAW_SAVE_ERR__\nImpossibile scrivere il file: " . $rel_out;
            exit;
        }

        echo "__SRAW_SAVE_OK__\n" . $rel_out;
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "__SRAW_SAVE_ERR__\n" . $e->getMessage();
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tool = $_POST['tool'] ?? '';
    $out  = '';
    $err  = '';

    try {
        if ($tool === 'mp3_to_sraw') {
            $inp_abs = rel_to_abs_data_path($_POST['input_mp3'] ?? '', true, false, ['mp3']);
            $out_rel = sanitize_output_rel_path($_POST['output_sraw'] ?? 'out.sraw', 'out.sraw', ['sraw']);
            ensure_parent_dir_exists_for_rel_file($out_rel);
            $out_abs = rel_to_abs_data_path($out_rel, false, false, ['sraw']);

            $ch = in_array($_POST['channel'] ?? 'MIX', ['L', 'R', 'MIX'], true) ? $_POST['channel'] : 'MIX';

            $args = [$inp_abs, $out_abs, '--channel', $ch, '--verbose'];
            $ffmpeg = trim($_POST['ffmpeg_path'] ?? '');
            if ($ffmpeg !== '') {
                $args[] = '--ffmpeg-path';
                $args[] = $ffmpeg;
            }

            $ok = run_python('mp3_to_sraw.py', $args, $out, $err);
            $message = $ok ? "✓ Convertito: " . h($out_rel) : '';
            $error   = $err ?: '';

        } elseif ($tool === 'sraw_to_mp3') {
            $inp_abs = rel_to_abs_data_path($_POST['input_sraw'] ?? '', true, false, ['sraw']);
            $out_rel = sanitize_output_rel_path($_POST['output_mp3'] ?? 'out.mp3', 'out.mp3', ['mp3']);
            ensure_parent_dir_exists_for_rel_file($out_rel);
            $out_abs = rel_to_abs_data_path($out_rel, false, false, ['mp3']);

            $br = preg_match('/^\d+k$/', $_POST['bitrate'] ?? '') ? $_POST['bitrate'] : '128k';
            $part = in_array($_POST['part'] ?? 'real', ['real', 'imag', 'modulus'], true) ? $_POST['part'] : 'real';

            $args = [$inp_abs, $out_abs, '--bitrate', $br, '--part', $part, '--verbose'];
            $ffmpeg = trim($_POST['ffmpeg_path'] ?? '');
            if ($ffmpeg !== '') {
                $args[] = '--ffmpeg-path';
                $args[] = $ffmpeg;
            }
            $sr = $_POST['sample_rate'] ?? '';
            if ($sr !== '' && ctype_digit($sr)) {
                $args[] = '--sample-rate';
                $args[] = $sr;
            }

            $ok = run_python('sraw_to_mp3.py', $args, $out, $err);
            $message = $ok ? "✓ Convertito: " . h($out_rel) : '';
            $error   = $err ?: '';

        } elseif ($tool === 'transformer') {
            $inp_abs = rel_to_abs_data_path($_POST['input_sraw'] ?? '', true, false, ['sraw']);
            $out_rel = sanitize_output_rel_path($_POST['output_sraw'] ?? 'spectrum.sraw', 'spectrum.sraw', ['sraw']);
            ensure_parent_dir_exists_for_rel_file($out_rel);
            $out_abs = rel_to_abs_data_path($out_rel, false, false, ['sraw']);

            $mode = in_array($_POST['mode'] ?? '', ['fft', 'dft', 'ifft', 'idft'], true) ? $_POST['mode'] : 'fft';

            $ok = run_python('transformer.py', [$inp_abs, $out_abs, '--mode', $mode, '--verbose', '--benchmark'], $out, $err);
            $message = $ok ? "✓ Trasformata: " . h($out_rel) : '';
            $error   = $err ?: '';
            if ($ok && $out !== '') {
                $message .= "<br><pre>" . h($out) . "</pre>";
            }

        } elseif ($tool === 'convolver') {
            $inp_a_abs = rel_to_abs_data_path($_POST['input_a'] ?? '', true, false, ['sraw']);
            $inp_b_abs = rel_to_abs_data_path($_POST['input_b'] ?? '', true, false, ['sraw']);
            $out_rel   = sanitize_output_rel_path($_POST['output_sraw'] ?? 'conv_out.sraw', 'conv_out.sraw', ['sraw']);
            ensure_parent_dir_exists_for_rel_file($out_rel);
            $out_abs   = rel_to_abs_data_path($out_rel, false, false, ['sraw']);

            $mode   = in_array($_POST['mode'] ?? '', ['conv', 'corr', 'xcorr'], true) ? $_POST['mode'] : 'conv';
            $domain = in_array($_POST['domain'] ?? '', ['time', 'freq'], true) ? $_POST['domain'] : 'time';

            $args = [$inp_a_abs, $inp_b_abs, $out_abs, '--mode', $mode, '--domain', $domain, '--verbose', '--benchmark'];
            $ok   = run_python('convolver.py', $args, $out, $err);

            $message = $ok ? "✓ Operazione di convoluzione/correlazione completata: " . h($out_rel) : '';
            $error   = $err ?: '';
            if ($ok && $out !== '') {
                $message .= "<br><pre>" . h($out) . "</pre>";
            }

        } elseif ($tool === 'sraw_op') {
            $op = in_array($_POST['op'] ?? '', ['sum', 'mul', 'gain', 'shift', 'mirror_y', 'conj', 'dilate_x'], true)
                ? $_POST['op']
                : 'gain';

            $inp_a_abs = rel_to_abs_data_path($_POST['input_a'] ?? '', true, false, ['sraw']);
            $out_rel   = sanitize_output_rel_path($_POST['output_sraw'] ?? 'sraw_ops_out.sraw', 'sraw_ops_out.sraw', ['sraw']);
            ensure_parent_dir_exists_for_rel_file($out_rel);
            $out_abs   = rel_to_abs_data_path($out_rel, false, false, ['sraw']);

            $args = [$inp_a_abs, $out_abs, '--op', $op, '--verbose', '--benchmark'];

            if (in_array($op, ['sum', 'mul'], true)) {
                $inp_b_abs = rel_to_abs_data_path($_POST['input_b'] ?? '', true, false, ['sraw']);
                $args[] = '--input-b';
                $args[] = $inp_b_abs;
            }

            if ($op === 'gain') {
                $args[] = '--gain';
                $args[] = (string)(float)($_POST['gain'] ?? 1.0);
            }

            if ($op === 'shift') {
                $shift_unit = in_array($_POST['shift_unit'] ?? '', ['samples', 'time', 'freq'], true)
                    ? $_POST['shift_unit']
                    : 'samples';

                $args[] = '--shift-value';
                $args[] = (string)(float)($_POST['shift_value'] ?? 0);
                $args[] = '--shift-unit';
                $args[] = $shift_unit;
            }

            if ($op === 'dilate_x') {
                $args[] = '--dilate-factor';
                $args[] = (string)(float)($_POST['dilate_factor'] ?? 1.0);
            }

            $ok = run_python('sraw_ops.py', $args, $out, $err);

            $message = $ok ? "✓ Operazione SRAW completata: " . h($out_rel) : '';
            $error   = $err ?: '';
            if ($ok && $out !== '') {
                $message .= "<br><pre>" . h($out) . "</pre>";
            }

        } elseif ($tool === 'filemgr_mkdir') {
            $parent_rel = $_POST['parent_dir'] ?? '';
            $new_name   = $_POST['new_dir_name'] ?? '';
            $created    = create_subdir($parent_rel, $new_name);
            $message    = "✓ Cartella creata: " . h($created);

        } elseif ($tool === 'filemgr_rename') {
            $target_rel = $_POST['target_rel'] ?? '';
            $new_name   = $_POST['new_name'] ?? '';
            $renamed    = rename_data_item($target_rel, $new_name);
            $message    = "✓ Rinominato: " . h($renamed);

        } elseif ($tool === 'filemgr_move') {
            $source_rel     = $_POST['source_rel'] ?? '';
            $target_dir_rel = $_POST['target_dir'] ?? '';
            $moved          = move_data_item($source_rel, $target_dir_rel);
            $message        = "✓ Spostato: " . h($moved);

        } elseif ($tool === 'filemgr_delete') {
            $target_rel = $_POST['target_rel'] ?? '';
            delete_data_item($target_rel);
            $message = "✓ Cancellato: " . h($target_rel);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}